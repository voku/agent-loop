<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

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
        $bindingStore = new ExecutionWorkspaceBindingStore($this->rootPath);
        $binding = $bindingStore->load($result->taskId, $result->stageId, $result->attempt);
        $candidateRevision = $bindingStore->assertCurrent($binding, $plan, $projection, $result, $workspacePath);
        $artifactDigests = $this->artifactDigests($workspacePath, $result->artifactReferences);
        $validationExitCodes = $this->validationEvidence($workspacePath, $contract, $plan, $result);

        $evidence = new StageResultEvidence(
            $result->submissionId,
            $result->taskId,
            $result->runId,
            $result->contractRevision,
            $result->executionPlanDigest,
            $result->stageId,
            $result->attempt,
            $candidateRevision,
            $binding->workspaceIdentity,
            $artifactDigests,
            $validationExitCodes,
            gmdate(DATE_ATOM),
        );
        $path = $this->path($result->taskId, $result->submissionId);
        if (is_file($path)) {
            $existing = $this->load($result->taskId, $result->submissionId);
            if ($existing->toArray() !== $evidence->toArray()) {
                throw new RuntimeException('TRANSITION_REJECTED: submission id already has different authoritative StageResult evidence.');
            }

            return $existing;
        }
        $this->write($path, $evidence->toArray());

        return $evidence;
    }

    public function recordDeterministic(
        ExecutionPlan $plan,
        ExecutionProjection $projection,
        StageResult $result,
        int $validationExitCode,
    ): StageResultEvidence {
        if ($projection->currentStageId !== $result->stageId || $projection->currentAttempt !== $result->attempt) {
            throw new RuntimeException('TRANSITION_REJECTED: deterministic StageResult evidence is stale for the current stage/attempt.');
        }
        $validation = array_fill_keys($result->validationReferences, $validationExitCode);
        ksort($validation, SORT_STRING);
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
            gmdate(DATE_ATOM),
        );
        $path = $this->path($result->taskId, $result->submissionId);
        if (!is_file($path)) {
            $this->write($path, $evidence->toArray());
        }

        return $this->assertMatches($plan, $result);
    }

    public function assertMatches(ExecutionPlan $plan, StageResult $result): StageResultEvidence
    {
        $evidence = $this->load($result->taskId, $result->submissionId);
        if ($evidence->taskId !== $result->taskId
            || $evidence->runId !== $result->runId
            || $evidence->contractRevision !== $result->contractRevision
            || !hash_equals($evidence->executionPlanDigest, $result->executionPlanDigest)
            || !hash_equals($plan->digest(), $result->executionPlanDigest)
            || $evidence->stageId !== $result->stageId
            || $evidence->attempt !== $result->attempt
            || !hash_equals($evidence->candidateRevision, $result->candidateRevision)) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative StageResult evidence does not match submitted result.');
        }
        if ($this->canonicalReferences(array_keys($evidence->artifactDigests)) !== $this->canonicalReferences($result->artifactReferences)
            || $this->canonicalReferences(array_keys($evidence->validationExitCodes)) !== $this->canonicalReferences($result->validationReferences)) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative StageResult references do not match submitted result.');
        }
        if ($result->outcome === StageOutcome::PASS) {
            foreach ($evidence->validationExitCodes as $exitCode) {
                if ($exitCode !== 0) {
                    throw new RuntimeException('STALE_EVIDENCE: deterministic PASS requires successful validation evidence.');
                }
            }
        }

        return $evidence;
    }

    public function assertFresh(StageResultEvidence $evidence, string $workspacePath): void
    {
        if ($evidence->workspaceIdentity === 'owner:deterministic') {
            return;
        }
        $plan = $this->planFor($evidence->taskId);
        if ($plan->baseCommit === null) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative candidate evidence has no governed Git base.');
        }
        $hasher = new ExecutionCandidateHasher($this->rootPath);
        if (!hash_equals($evidence->workspaceIdentity, $hasher->workspaceIdentity($workspacePath))) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative StageResult workspace changed after observation.');
        }
        $candidateRevision = $hasher->candidateRevision($workspacePath, $plan->baseCommit);
        if (!hash_equals($evidence->candidateRevision, $candidateRevision)) {
            throw new RuntimeException('STALE_EVIDENCE: candidate changed after authoritative evidence observation.');
        }
        $artifactDigests = $this->artifactDigests($workspacePath, array_keys($evidence->artifactDigests));
        if ($artifactDigests !== $evidence->artifactDigests) {
            throw new RuntimeException('STALE_EVIDENCE: artifact evidence changed after authoritative observation.');
        }
    }

    public function load(string $taskId, string $submissionId): StageResultEvidence
    {
        $path = $this->path($taskId, $submissionId);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('MISSING_EVIDENCE: authoritative StageResult evidence is missing.');
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('MISSING_EVIDENCE: authoritative StageResult evidence is missing.');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid authoritative StageResult evidence JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'authoritative_stage_result_evidence') {
            throw new RuntimeException('Unsupported authoritative StageResult evidence record: ' . $path);
        }
        $contractRevision = $data['contract_revision'] ?? null;
        $attempt = $data['attempt'] ?? null;
        if (!is_int($contractRevision) || !is_int($attempt)) {
            throw new RuntimeException('Authoritative StageResult evidence numeric fields are invalid: ' . $path);
        }

        return new StageResultEvidence(
            $this->string($data, 'submission_id', $path),
            $this->string($data, 'task_id', $path),
            $this->string($data, 'run_id', $path),
            $contractRevision,
            $this->string($data, 'execution_plan_digest', $path),
            $this->string($data, 'stage_id', $path),
            $attempt,
            $this->string($data, 'candidate_revision', $path),
            $this->string($data, 'workspace_identity', $path),
            $this->stringMap($data['artifact_digests'] ?? null, 'artifact_digests'),
            $this->intMap($data['validation_exit_codes'] ?? null, 'validation_exit_codes'),
            $this->string($data, 'observed_at', $path),
        );
    }

    private function planFor(string $taskId): ExecutionPlan
    {
        $path = (new ProjectLayout($this->rootPath))->executionPlanPath($taskId);
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('STALE_EVIDENCE: governed execution plan is missing.');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid governed execution plan JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Invalid governed execution plan record: ' . $path);
        }

        return ExecutionPlanStore::fromArray($data);
    }

    /** @param list<string> $references
     *  @return array<non-empty-string, non-empty-string>
     */
    private function artifactDigests(string $workspacePath, array $references): array
    {
        if ($references === []) {
            return [];
        }
        $root = realpath($workspacePath);
        if (!is_string($root)) {
            throw new RuntimeException('TRANSITION_REJECTED: workspace cannot be resolved for artifact evidence.');
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $digests = [];
        foreach ($references as $reference) {
            if (str_starts_with($reference, '/') || str_contains($reference, "\0")) {
                throw new RuntimeException('TRANSITION_REJECTED: artifact reference must be a non-empty workspace-relative path.');
            }
            $candidate = $root . '/' . $reference;
            $parent = realpath(dirname($candidate));
            if (!is_string($parent)) {
                throw new RuntimeException('MISSING_EVIDENCE artifact does not exist: ' . $reference);
            }
            $parent = rtrim(str_replace('\\', '/', $parent), '/');
            if ($parent !== $root && !str_starts_with($parent, $root . '/')) {
                throw new RuntimeException('TRANSITION_REJECTED: artifact reference escapes the bound workspace: ' . $reference);
            }
            if (is_link($candidate)) {
                $target = readlink($candidate);
                if (!is_string($target)) {
                    throw new RuntimeException('MISSING_EVIDENCE artifact cannot be read: ' . $reference);
                }
                $digests[$reference] = 'sha256:' . hash('sha256', 'symlink:' . $target);
                continue;
            }
            if (!is_file($candidate)) {
                throw new RuntimeException('MISSING_EVIDENCE artifact does not exist: ' . $reference);
            }
            $digest = hash_file('sha256', $candidate);
            if (!is_string($digest)) {
                throw new RuntimeException('MISSING_EVIDENCE artifact cannot be hashed: ' . $reference);
            }
            $digests[$reference] = 'sha256:' . $digest;
        }
        ksort($digests, SORT_STRING);

        return $digests;
    }

    /** @return array<non-empty-string, int> */
    private function validationEvidence(string $workspacePath, TaskContract $contract, ExecutionPlan $plan, StageResult $result): array
    {
        $stage = $plan->stage($result->stageId);
        if ($stage->mayMutate && $result->outcome === StageOutcome::COMPLETED) {
            $missing = array_values(array_diff($contract->validation, $result->validationReferences));
            if ($missing !== []) {
                throw new RuntimeException('MISSING_EVIDENCE mutating completion requires every current Contract validation obligation.');
            }
        }
        $allowed = array_fill_keys($contract->validation, true);
        $exitCodes = [];
        foreach ($result->validationReferences as $command) {
            if (!isset($allowed[$command])) {
                throw new RuntimeException('TRANSITION_REJECTED: validation reference is not a current Contract obligation: ' . $command);
            }
            $exitCode = $this->executeDeclaredValidationShell($workspacePath, $command);
            $exitCodes[$command] = $exitCode;
            if ($exitCode !== 0) {
                throw new RuntimeException('TRANSITION_REJECTED: validation evidence failed for current candidate: ' . $command);
            }
        }
        ksort($exitCodes, SORT_STRING);

        return $exitCodes;
    }

    private function executeDeclaredValidationShell(string $workspacePath, string $command): int
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = proc_open(
            $command,
            [
                0 => ['file', $null, 'r'],
                1 => ['file', $null, 'a'],
                2 => ['file', $null, 'a'],
            ],
            $pipes,
            $workspacePath,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('TRANSITION_REJECTED: unable to execute declared validation command.');
        }

        return proc_close($process);
    }

    /**
     * @param list<non-empty-string> $references
     * @return list<non-empty-string>
     */
    private function canonicalReferences(array $references): array
    {
        sort($references, SORT_STRING);

        return $references;
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
            throw new RuntimeException('Unable to create authoritative StageResult evidence directory: ' . $directory);
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
    private function string(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Authoritative StageResult evidence field %s is invalid in %s.', $key, $path));
        }

        return trim($value);
    }

    /** @return array<non-empty-string, non-empty-string> */
    private function stringMap(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' must be an object.');
        }
        /** @var array<non-empty-string, non-empty-string> $result */
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_string($item)) {
                throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' contains an invalid entry.');
            }
            $normalizedKey = trim($key);
            $normalizedItem = trim($item);
            if ($normalizedKey === '' || $normalizedItem === '') {
                throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' contains an invalid entry.');
            }
            $result[$normalizedKey] = $normalizedItem;
        }
        ksort($result, SORT_STRING);

        return $result;
    }

    /** @return array<non-empty-string, int> */
    private function intMap(mixed $value, string $label): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' must be an object.');
        }
        /** @var array<non-empty-string, int> $result */
        $result = [];
        foreach ($value as $key => $item) {
            if (!is_string($key) || !is_int($item)) {
                throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' contains an invalid entry.');
            }
            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                throw new RuntimeException('STALE_EVIDENCE: authoritative ' . $label . ' contains an invalid entry.');
            }
            $result[$normalizedKey] = $item;
        }
        ksort($result, SORT_STRING);

        return $result;
    }
}
