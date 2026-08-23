<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;

final readonly class ExecutionStateStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function prepare(ExecutionPlan $plan): ExecutionState
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            return $this->prepareUnlocked($plan);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function projection(ExecutionPlan $plan): ExecutionProjection
    {
        return $this->projectionFromState($plan, $this->loadForPlan($plan));
    }

    public function assertEvidenceClaim(ExecutionPlan $plan, ExecutionEvidenceClaim $claim): void
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            $state = $this->find($plan->taskId) ?? $this->prepareUnlocked($plan);
            $this->assertBinding($state, $plan);
            $this->authority()->assertClaimCurrent($plan, $state, $claim);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function accept(ExecutionPlan $plan, StageResult $result): ExecutionProjection
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            $state = $this->find($plan->taskId) ?? $this->prepareUnlocked($plan);
            $this->assertBinding($state, $plan);

            foreach ($state->history as $accepted) {
                if ($accepted->result->submissionId !== $result->submissionId) {
                    continue;
                }
                if ($accepted->result->toArray() !== $result->toArray()) {
                    throw new RuntimeException('Stage submission id was already accepted with different content: ' . $result->submissionId);
                }

                return $this->projectionFromState($plan, $state);
            }

            if ($state->attention !== null) {
                throw new RuntimeException('Execution is waiting for Attention ' . $state->attention->id . '.');
            }
            if ($state->currentStageId === null) {
                throw new RuntimeException('Execution plan is already complete.');
            }
            $this->assertResultBinding($state, $plan, $result);
            $stage = $plan->stage($state->currentStageId);
            $this->authority()->assertAcceptable($plan, $state, $stage, $result);
            $acceptedAt = $this->now();
            $history = $state->history;

            if (in_array($result->outcome, [StageOutcome::BLOCKED, StageOutcome::NEEDS_CLARIFICATION, StageOutcome::FAILED], true)) {
                $attention = new AttentionRequest(
                    'attention:' . bin2hex(random_bytes(8)),
                    $plan->taskId,
                    $plan->runId,
                    $result->outcome === StageOutcome::NEEDS_CLARIFICATION
                        ? AttentionKind::CLARIFICATION_REQUIRED
                        : AttentionKind::RUNNER_FAILED,
                    trim($result->summary) !== '' ? trim($result->summary) : 'Execution stage requires human attention.',
                    $stage->id,
                    $acceptedAt,
                );
                $history[] = new AcceptedStageResult($result, null, $acceptedAt);
                $next = new ExecutionState(
                    $state->taskId,
                    $state->runId,
                    $state->contractRevision,
                    $state->executionPlanDigest,
                    $state->currentStageId,
                    $state->currentAttempt,
                    $result->candidateRevision,
                    $attention,
                    $history,
                );
                $this->write($next);

                return $this->projectionFromState($plan, $next);
            }

            $nextStageId = $stage->next($result->outcome);
            $handoff = new HandoffEnvelope(
                $plan->taskId,
                $plan->runId,
                $plan->contractRevision,
                $stage->id,
                $nextStageId,
                $result->candidateRevision,
                $plan->digest(),
                $result->artifactReferences,
                $result->validationReferences,
                $acceptedAt,
            );
            $history[] = new AcceptedStageResult($result, $handoff, $acceptedAt);
            $nextAttempt = $nextStageId === null ? 0 : $this->attemptFor($nextStageId, $history);
            $next = new ExecutionState(
                $state->taskId,
                $state->runId,
                $state->contractRevision,
                $state->executionPlanDigest,
                $nextStageId,
                $nextAttempt,
                $result->candidateRevision,
                null,
                $history,
            );
            $this->write($next);

            return $this->projectionFromState($plan, $next);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function resolveAttention(ExecutionPlan $plan, string $attentionId): ExecutionProjection
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            $state = $this->find($plan->taskId) ?? $this->prepareUnlocked($plan);
            $this->assertBinding($state, $plan);
            if ($state->attention === null || $state->attention->id !== $attentionId) {
                throw new RuntimeException('No matching pending Attention exists for this execution.');
            }
            if ($state->currentStageId === null) {
                throw new RuntimeException('Completed execution cannot resolve stage Attention.');
            }
            (new AttentionResolutionStore($this->rootPath))->assertCurrent(
                $plan,
                $state->attention,
                $state->currentAttempt,
            );

            $next = new ExecutionState(
                $state->taskId,
                $state->runId,
                $state->contractRevision,
                $state->executionPlanDigest,
                $state->currentStageId,
                $state->currentAttempt + 1,
                $state->candidateRevision,
                null,
                $state->history,
            );
            $this->write($next);

            return $this->projectionFromState($plan, $next);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function find(string $taskId): ?ExecutionState
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read execution state: ' . $path);
        }

        return $this->decode($contents, $path, $taskId);
    }

    public function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->executionStatePath($taskId);
    }

    private function prepareUnlocked(ExecutionPlan $plan): ExecutionState
    {
        $existing = $this->find($plan->taskId);
        if ($existing !== null) {
            $this->assertBinding($existing, $plan);

            return $existing;
        }

        $state = new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            $plan->firstStageId(),
            $plan->firstStageId() === null ? 0 : 1,
            $plan->baseCommit ?? 'run:' . $plan->runId . ':initial',
            null,
            [],
        );
        $this->write($state);

        return $state;
    }

    private function loadForPlan(ExecutionPlan $plan): ExecutionState
    {
        $state = $this->find($plan->taskId) ?? $this->prepare($plan);
        $this->assertBinding($state, $plan);

        return $state;
    }

    private function assertBinding(ExecutionState $state, ExecutionPlan $plan): void
    {
        if ($state->taskId !== $plan->taskId
            || $state->runId !== $plan->runId
            || $state->contractRevision !== $plan->contractRevision
            || !hash_equals($state->executionPlanDigest, $plan->digest())) {
            throw new RuntimeException('Execution state is stale for the current governed execution plan.');
        }
    }

    private function assertResultBinding(ExecutionState $state, ExecutionPlan $plan, StageResult $result): void
    {
        if ($result->taskId !== $plan->taskId
            || $result->runId !== $plan->runId
            || $result->contractRevision !== $plan->contractRevision
            || !hash_equals($result->executionPlanDigest, $plan->digest())
            || $result->stageId !== $state->currentStageId
            || $result->attempt !== $state->currentAttempt) {
            throw new RuntimeException('Stage result does not match the current execution stage binding.');
        }
    }

    /** @param list<AcceptedStageResult> $history */
    private function attemptFor(string $stageId, array $history): int
    {
        $accepted = 0;
        foreach ($history as $entry) {
            if ($entry->result->stageId === $stageId) {
                ++$accepted;
            }
        }

        return $accepted + 1;
    }

    private function projectionFromState(ExecutionPlan $plan, ExecutionState $state): ExecutionProjection
    {
        $handoffs = [];
        foreach ($state->history as $accepted) {
            if ($accepted->handoff !== null) {
                $handoffs[] = $accepted->handoff;
            }
        }

        return new ExecutionProjection(
            $state->taskId,
            $state->runId,
            $state->contractRevision,
            $plan->profile,
            $state->executionPlanDigest,
            $state->currentStageId,
            $state->currentAttempt,
            $state->attention,
            $handoffs,
            $state->candidateRevision,
        );
    }

    private function write(ExecutionState $state): void
    {
        $path = $this->path($state->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create execution state directory: ' . $directory);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($state->toArray())) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('Unable to write execution state: ' . $path);
        }
    }

    /** @return resource */
    private function acquireLock(string $taskId): mixed
    {
        $path = (new ProjectLayout($this->rootPath))->executionStateLockPath($taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create execution lock directory: ' . $directory);
        }
        $lock = fopen($path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to acquire execution state lock: ' . $path);
        }

        return $lock;
    }

    /** @param resource $lock */
    private function releaseLock(mixed $lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function decode(string $json, string $path, string $expectedTaskId): ExecutionState
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid execution state JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'governed_execution_state') {
            throw new RuntimeException('Unsupported execution state schema in ' . $path . '.');
        }
        $taskId = $this->requiredString($data, 'task_id', $path);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException('Execution state task id does not match requested task.');
        }
        $revision = $data['contract_revision'] ?? null;
        $attempt = $data['current_attempt'] ?? null;
        if (!is_int($revision) || $revision < 1 || !is_int($attempt) || $attempt < 0) {
            throw new RuntimeException('Execution state revision/attempt fields are invalid in ' . $path . '.');
        }
        $currentStageId = $data['current_stage_id'] ?? null;
        if ($currentStageId !== null && (!is_string($currentStageId) || trim($currentStageId) === '')) {
            throw new RuntimeException('Execution state current_stage_id must be a non-empty string or null.');
        }

        return new ExecutionState(
            $taskId,
            $this->requiredString($data, 'run_id', $path),
            $revision,
            $this->requiredDigest($data, 'execution_plan_digest', $path),
            is_string($currentStageId) ? trim($currentStageId) : null,
            $attempt,
            $this->requiredString($data, 'candidate_revision', $path),
            $this->decodeAttention($data['attention'] ?? null, $path),
            $this->decodeHistory($data['history'] ?? null, $path),
        );
    }

    private function decodeAttention(mixed $value, string $path): ?AttentionRequest
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Execution state attention must be an object or null in ' . $path . '.');
        }
        $kind = AttentionKind::tryFrom($this->requiredString($value, 'kind', $path . '#attention'));
        if (!$kind instanceof AttentionKind) {
            throw new RuntimeException('Unsupported execution Attention kind in ' . $path . '.');
        }
        $stageId = $value['stage_id'] ?? null;
        if ($stageId !== null && (!is_string($stageId) || trim($stageId) === '')) {
            throw new RuntimeException('Execution Attention stage_id must be a string or null.');
        }

        return new AttentionRequest(
            $this->requiredString($value, 'id', $path . '#attention'),
            $this->requiredString($value, 'task_id', $path . '#attention'),
            $this->requiredString($value, 'run_id', $path . '#attention'),
            $kind,
            $this->requiredString($value, 'message', $path . '#attention'),
            is_string($stageId) ? trim($stageId) : null,
            $this->requiredString($value, 'created_at', $path . '#attention'),
        );
    }

    /** @return list<AcceptedStageResult> */
    private function decodeHistory(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Execution state history must be an array in ' . $path . '.');
        }
        $history = [];
        foreach ($value as $index => $entry) {
            if (!is_array($entry) || !is_array($entry['result'] ?? null)) {
                throw new RuntimeException('Execution state history entry is invalid in ' . $path . '.');
            }
            $result = $this->decodeResult($entry['result'], $path . '#history[' . $index . '].result');
            $handoffValue = $entry['handoff'] ?? null;
            $handoff = $handoffValue === null ? null : $this->decodeHandoff($handoffValue, $path . '#history[' . $index . '].handoff');
            $history[] = new AcceptedStageResult(
                $result,
                $handoff,
                $this->requiredString($entry, 'accepted_at', $path . '#history[' . $index . ']'),
            );
        }

        return $history;
    }

    /** @param array<string, mixed> $data */
    private function decodeResult(array $data, string $path): StageResult
    {
        $revision = $data['contract_revision'] ?? null;
        $attempt = $data['attempt'] ?? null;
        if (!is_int($revision) || $revision < 1 || !is_int($attempt) || $attempt < 1) {
            throw new RuntimeException('Stage result revision/attempt is invalid in ' . $path . '.');
        }
        $outcome = StageOutcome::tryFrom($this->requiredString($data, 'outcome', $path));
        if (!$outcome instanceof StageOutcome) {
            throw new RuntimeException('Unsupported StageResult outcome in ' . $path . '.');
        }

        return new StageResult(
            $this->requiredString($data, 'submission_id', $path),
            $this->requiredString($data, 'task_id', $path),
            $this->requiredString($data, 'run_id', $path),
            $revision,
            $this->requiredDigest($data, 'execution_plan_digest', $path),
            $this->requiredString($data, 'stage_id', $path),
            $attempt,
            $outcome,
            $this->requiredString($data, 'candidate_revision', $path),
            ExecutionArtifactValue::stringList($data['artifact_references'] ?? null, $path . '#artifact_references'),
            ExecutionArtifactValue::stringList($data['validation_references'] ?? null, $path . '#validation_references'),
            is_string($data['summary'] ?? null) ? trim($data['summary']) : '',
        );
    }

    private function decodeHandoff(mixed $value, string $path): HandoffEnvelope
    {
        if (!is_array($value)) {
            throw new RuntimeException('Execution handoff must be an object in ' . $path . '.');
        }
        if (($value['schema_version'] ?? null) !== '1.0' || ($value['kind'] ?? null) !== 'execution_handoff') {
            throw new RuntimeException('Unsupported execution handoff schema in ' . $path . '.');
        }
        $revision = $value['contract_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Execution handoff Contract revision is invalid in ' . $path . '.');
        }
        $toStage = $value['to_stage'] ?? null;
        if ($toStage !== null && (!is_string($toStage) || trim($toStage) === '')) {
            throw new RuntimeException('Execution handoff to_stage must be string or null in ' . $path . '.');
        }

        return new HandoffEnvelope(
            $this->requiredString($value, 'task_id', $path),
            $this->requiredString($value, 'run_id', $path),
            $revision,
            $this->requiredString($value, 'from_stage', $path),
            is_string($toStage) ? trim($toStage) : null,
            $this->requiredString($value, 'candidate_revision', $path),
            $this->requiredDigest($value, 'execution_plan_digest', $path),
            ExecutionArtifactValue::stringList($value['artifact_references'] ?? null, $path . '#artifact_references'),
            ExecutionArtifactValue::stringList($value['validation_references'] ?? null, $path . '#validation_references'),
            $this->requiredString($value, 'accepted_at', $path),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return non-empty-string
     */
    private function requiredDigest(array $data, string $key, string $path): string
    {
        return ExecutionArtifactValue::sha256($data[$key] ?? null, $path . '#' . $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return non-empty-string
     */
    private function requiredString(array $data, string $key, string $path): string
    {
        return ExecutionArtifactValue::string($data[$key] ?? null, $path . '#' . $key);
    }

    private function authority(): ExecutionStageResultAuthority
    {
        return new ExecutionStageResultAuthority($this->rootPath);
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }
}
