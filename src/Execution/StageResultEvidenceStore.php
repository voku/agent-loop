<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Workflow\TaskContract;

final readonly class StageResultEvidenceStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function observe(
        TaskContract $contract,
        ExecutionPlan $plan,
        ExecutionProjection $projection,
        StageResult $result,
        string $workspacePath,
    ): StageResultEvidence {
        $this->assertResultBinding($plan, $projection, $result);
        $path = $this->path($result->taskId, $result->submissionId);
        if (is_file($path)) {
            $existing = $this->load($result->taskId, $result->submissionId);
            $this->assertMatches($plan, $result);
            $this->assertFresh($plan, $result, $workspacePath);

            return $existing;
        }

        $stage = $plan->stage($result->stageId);
        $bindings = new ExecutionWorkspaceBindingStore($this->rootPath);
        $binding = $bindings->load($result->taskId, $result->stageId, $result->attempt);
        $candidate = $bindings->assertCurrent($binding, $plan, $projection, $result, $workspacePath);
        if (!$stage->mayMutate && !hash_equals($binding->initialCandidateRevision, $candidate)) {
            throw new RuntimeException('STALE_WORKSPACE: read-only execution stage changed the owner-bound candidate.');
        }

        $artifactDigests = $this->artifactDigests($workspacePath, $result->artifactReferences);
        $validationExitCodes = $this->validationEvidence($workspacePath, $contract, $stage, $result);
        $afterValidation = (new ExecutionCandidateHasher($this->rootPath))->candidateRevision($workspacePath, $binding->baseCommit);
        if (!hash_equals($candidate, $afterValidation)) {
            throw new RuntimeException('STALE_EVIDENCE: declared validation changed the candidate while evidence was being observed.');
        }

        $evidence = new StageResultEvidence(
            $result->submissionId,
            $result->taskId,
            $result->runId,
            $result->contractRevision,
            $result->executionPlanDigest,
            $result->stageId,
            $result->attempt,
            $candidate,
            $binding->workspaceIdentity,
            $artifactDigests,
            $validationExitCodes,
            (new DateTimeImmutable())->format(DATE_ATOM),
        );
        $this->write($path, $evidence->toArray());

        return $evidence;
    }

    public function recordDeterministic(
        ExecutionPlan $plan,
        ExecutionProjection $projection,
        StageResult $result,
    ): StageResultEvidence {
        $this->assertResultBinding($plan, $projection, $result);
        $stage = $plan->stage($result->stageId);
        if ($stage->kind !== ExecutionStageKind::DETERMINISTIC) {
            throw new RuntimeException('TRANSITION_REJECTED: deterministic owner evidence requires a deterministic stage.');
        }
        if (!hash_equals($projection->candidateRevision, $result->candidateRevision)) {
            throw new RuntimeException('STALE_EVIDENCE: deterministic StageResult changed candidate identity.');
        }
        if ($result->artifactReferences !== []) {
            throw new RuntimeException('TRANSITION_REJECTED: deterministic StageResult must not invent artifact references.');
        }

        $path = $this->path($result->taskId, $result->submissionId);
        if (is_file($path)) {
            return $this->assertMatches($plan, $result);
        }
        $validation = [];
        foreach ($result->validationReferences as $reference) {
            $validation[$reference] = 0;
        }
        $evidence = new StageResultEvidence(
            $result->submissionId,
            $result->taskId,
            $result->runId,
            $result->contractRevision,
            $result->executionPlanDigest,
            $result->stageId,
            $result->attempt,
            $result->candidateRevision,
            'owner:deterministic',
            [],
            $validation,
            (new DateTimeImmutable())->format(DATE_ATOM),
        );
        $this->write($path, $evidence->toArray());

        return $evidence;
    }

    public function load(string $taskId, string $submissionId): StageResultEvidence
    {
        $path = $this->path($taskId, $submissionId);
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('TRANSITION_REJECTED: MISSING_EVIDENCE authoritative StageResult evidence is missing.');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('STALE_EVIDENCE: invalid authoritative StageResult evidence JSON.', 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'authoritative_stage_result_evidence') {
            throw new RuntimeException('STALE_EVIDENCE: unsupported authoritative StageResult evidence record.');
        }
        $contractRevision = $data['contract_revision'] ?? null;
        $attempt = $data['attempt'] ?? null;
        $artifactDigests = $this->stringMap($data['artifact_digests'] ?? null, 'artifact digests');
        $validationExitCodes = $this->intMap($data['validation_exit_codes'] ?? null, 'validation exit codes');
        if (!is_int($contractRevision) || !is_int($attempt)) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative StageResult evidence numeric fields are invalid.');
        }

        return new StageResultEvidence(
            $this->string($data, 'submission_id'),
            $this->string($data, 'task_id'),
            $this->string($data, 'run_id'),
            $contractRevision,
            $this->string($data, 'execution_plan_digest'),
            $this->string($data, 'stage_id'),
            $attempt,
            $this->string($data, 'candidate_revision'),
            $this->string($data, 'workspace_identity'),
            $artifactDigests,
            $validationExitCodes,
            $this->string($data, 'observed_at'),
        );
    }

    public function assertMatches(ExecutionPlan $plan, StageResult $result): StageResultEvidence
    {
        $evidence = $this->load($result->taskId, $result->submissionId);
        if ($evidence->submissionId !== $result->submissionId
            || $evidence->taskId !== $plan->taskId
            || $evidence->runId !== $plan->runId
            || $evidence->contractRevision !== $plan->contractRevision
            || !hash_equals($evidence->executionPlanDigest, $plan->digest())
            || $evidence->stageId !== $result->stageId
            || $evidence->attempt !== $result->attempt
            || !hash_equals($evidence->candidateRevision, $result->candidateRevision)
            || array_keys($evidence->artifactDigests) !== $result->artifactReferences
            || array_keys($evidence->validationExitCodes) !== $result->validationReferences) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative StageResult evidence does not match submitted result.');
        }
        foreach ($evidence->validationExitCodes as $exitCode) {
            if ($exitCode !== 0) {
                throw new RuntimeException('TRANSITION_REJECTED: validation evidence is not passing.');
            }
        }

        return $evidence;
    }

    public function assertFresh(ExecutionPlan $plan, StageResult $result, string $workspacePath): StageResultEvidence
    {
        $evidence = $this->assertMatches($plan, $result);
        $binding = (new ExecutionWorkspaceBindingStore($this->rootPath))->load(
            $result->taskId,
            $result->stageId,
            $result->attempt,
        );
        $hasher = new ExecutionCandidateHasher($this->rootPath);
        if (!hash_equals($evidence->workspaceIdentity, $hasher->workspaceIdentity($workspacePath))) {
            throw new RuntimeException('STALE_EVIDENCE: owner evidence workspace identity changed before acceptance.');
        }
        $candidate = $hasher->candidateRevision($workspacePath, $binding->baseCommit);
        if (!hash_equals($candidate, $result->candidateRevision)) {
            throw new RuntimeException('STALE_EVIDENCE: candidate changed after authoritative evidence observation.');
        }
        if ($this->artifactDigests($workspacePath, $result->artifactReferences) !== $evidence->artifactDigests) {
            throw new RuntimeException('STALE_EVIDENCE: referenced artifact changed after authoritative evidence observation.');
        }

        return $evidence;
    }

    private function assertResultBinding(ExecutionPlan $plan, ExecutionProjection $projection, StageResult $result): void
    {
        if ($result->taskId !== $plan->taskId
            || $result->runId !== $plan->runId
            || $result->contractRevision !== $plan->contractRevision
            || !hash_equals($result->executionPlanDigest, $plan->digest())
            || $projection->currentStageId !== $result->stageId
            || $projection->currentAttempt !== $result->attempt) {
            throw new RuntimeException('TRANSITION_REJECTED: StageResult evidence request is stale for the current execution binding.');
        }
    }

    /**
     * @param list<non-empty-string> $references
     * @return array<non-empty-string, non-empty-string>
     */
    private function artifactDigests(string $workspacePath, array $references): array
    {
        $root = realpath($workspacePath);
        if (!is_string($root)) {
            throw new RuntimeException('STALE_WORKSPACE: execution workspace cannot be resolved for artifact evidence.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $digests = [];
        foreach ($references as $reference) {
            if ($reference === '' || str_starts_with($reference, '/') || str_contains($reference, "\0")) {
                throw new RuntimeException('TRANSITION_REJECTED: artifact reference must be a non-empty workspace-relative path.');
            }
            $candidate = $root . '/' . $reference;
            $parent = realpath(dirname($candidate));
            if (!is_string($parent)) {
                throw new RuntimeException('TRANSITION_REJECTED: MISSING_EVIDENCE artifact parent does not exist: ' . $reference);
            }
            $parent = rtrim(str_replace('\\', '/', $parent), '/');
            if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
                throw new RuntimeException('TRANSITION_REJECTED: artifact reference escapes the owner-bound workspace: ' . $reference);
            }
            if (is_link($candidate)) {
                $target = readlink($candidate);
                if (!is_string($target)) {
                    throw new RuntimeException('TRANSITION_REJECTED: MISSING_EVIDENCE artifact symlink is unreadable: ' . $reference);
                }
                $digests[$reference] = 'sha256:' . hash('sha256', 'symlink:' . $target);
                continue;
            }
            if (!is_file($candidate)) {
                throw new RuntimeException('TRANSITION_REJECTED: MISSING_EVIDENCE artifact does not exist: ' . $reference);
            }
            $digest = hash_file('sha256', $candidate);
            if (!is_string($digest)) {
                throw new RuntimeException('TRANSITION_REJECTED: MISSING_EVIDENCE artifact cannot be hashed: ' . $reference);
            }
            $digests[$reference] = 'sha256:' . $digest;
        }

        return $digests;
    }

    /** @return array<non-empty-string, int> */
    private function validationEvidence(string $workspacePath, TaskContract $contract, ExecutionStage $stage, StageResult $result): array
    {
        $references = $result->validationReferences;
        if ($stage->mayMutate && $result->outcome === StageOutcome::COMPLETED && $references !== $contract->validation) {
            throw new RuntimeException('TRANSITION_REJECTED: MISSING_EVIDENCE mutating completion requires every current Contract validation obligation.');
        }
        $allowed = array_fill_keys($contract->validation, true);
        $exitCodes = [];
        foreach ($references as $command) {
            if (!isset($allowed[$command])) {
                throw new RuntimeException('TRANSITION_REJECTED: validation reference is not a current Contract obligation: ' . $command);
            }
            $exitCode = $this->executeValidation($workspacePath, $command);
            $exitCodes[$command] = $exitCode;
            if ($exitCode !== 0) {
                throw new RuntimeException('TRANSITION_REJECTED: validation evidence failed for current candidate: ' . $command);
            }
        }

        return $exitCodes;
    }

    private function executeValidation(string $workspacePath, string $command): int
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            $command,
            [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
            $pipes,
            $workspacePath,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('TRANSITION_REJECTED: unable to execute declared validation obligation: ' . $command);
        }
        $exitCode = proc_close($process);
        if ($exitCode < 0) {
            throw new RuntimeException('TRANSITION_REJECTED: validation obligation terminated without an observable exit code: ' . $command);
        }

        return $exitCode;
    }

    private function path(string $taskId, string $submissionId): string
    {
        return (new ProjectLayout($this->rootPath))->stageResultEvidencePath($taskId, $submissionId);
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create StageResult evidence directory: ' . $directory);
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, CanonicalJson::pretty($data)) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('Unable to persist authoritative StageResult evidence: ' . $path);
        }
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('STALE_EVIDENCE: authoritative StageResult field is invalid: ' . $key);
        }

        return trim($value);
    }

    /** @return array<non-empty-string, non-empty-string> */
    private function stringMap(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '' || !is_string($item) || $item === '') {
                throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' contains invalid fields.');
            }
            $result[$key] = $item;
        }

        return $result;
    }

    /** @return array<non-empty-string, int> */
    private function intMap(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' must be an object.');
        }
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || $key === '' || !is_int($item)) {
                throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' contains invalid fields.');
            }
            $result[$key] = $item;
        }

        return $result;
    }
}
