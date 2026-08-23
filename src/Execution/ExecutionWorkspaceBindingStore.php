<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;

final readonly class ExecutionWorkspaceBindingStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function bind(ExecutionPlan $plan, ExecutionProjection $projection, string $stageId, int $attempt, string $workspacePath): ExecutionWorkspaceBinding
    {
        if ($projection->currentStageId !== $stageId || $projection->currentAttempt !== $attempt) {
            throw new RuntimeException('Execution workspace binding is stale for the current stage/attempt.');
        }
        if ($plan->baseCommit === null) {
            throw new RuntimeException('Execution workspace binding requires an exact governed Git base commit.');
        }

        $hasher = new ExecutionCandidateHasher($this->rootPath);
        $initialCandidate = $hasher->candidateRevision($workspacePath, $plan->baseCommit);
        if (!hash_equals($projection->candidateRevision, $initialCandidate)) {
            throw new RuntimeException('STALE_WORKSPACE: workspace candidate does not match authoritative execution projection.');
        }
        $workspaceIdentity = $hasher->workspaceIdentity($workspacePath);
        $path = $this->path($plan->taskId, $stageId, $attempt);
        if (is_file($path)) {
            $existing = $this->load($plan->taskId, $stageId, $attempt);
            $this->assertBindingIdentity(
                $existing,
                $plan,
                $stageId,
                $attempt,
                $workspaceIdentity,
                $initialCandidate,
            );

            return $existing;
        }

        $binding = new ExecutionWorkspaceBinding(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            $stageId,
            $attempt,
            $plan->baseCommit,
            $workspaceIdentity,
            $initialCandidate,
            (new DateTimeImmutable())->format(DATE_ATOM),
        );
        $this->write($path, $binding->toArray());

        return $binding;
    }

    public function load(string $taskId, string $stageId, int $attempt): ExecutionWorkspaceBinding
    {
        $path = $this->path($taskId, $stageId, $attempt);
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('STALE_WORKSPACE: owner workspace binding is missing for the stage attempt.');
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid owner workspace binding JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'execution_workspace_binding') {
            throw new RuntimeException('Unsupported owner workspace binding record: ' . $path);
        }
        $contractRevision = $data['contract_revision'] ?? null;
        $attemptValue = $data['attempt'] ?? null;
        if (!is_int($contractRevision) || !is_int($attemptValue)) {
            throw new RuntimeException('Owner workspace binding numeric fields are invalid: ' . $path);
        }

        return new ExecutionWorkspaceBinding(
            $this->string($data, 'task_id', $path),
            $this->string($data, 'run_id', $path),
            $contractRevision,
            $this->string($data, 'execution_plan_digest', $path),
            $this->string($data, 'stage_id', $path),
            $attemptValue,
            $this->string($data, 'base_commit', $path),
            $this->string($data, 'workspace_identity', $path),
            $this->string($data, 'initial_candidate_revision', $path),
            $this->string($data, 'bound_at', $path),
        );
    }

    public function assertCurrent(ExecutionWorkspaceBinding $binding, ExecutionPlan $plan, ExecutionProjection $projection, StageResult $result, string $workspacePath): string
    {
        if ($binding->taskId !== $plan->taskId
            || $binding->runId !== $plan->runId
            || $binding->contractRevision !== $plan->contractRevision
            || !hash_equals($binding->executionPlanDigest, $plan->digest())
            || $binding->stageId !== $result->stageId
            || $binding->attempt !== $result->attempt
            || $projection->currentStageId !== $result->stageId
            || $projection->currentAttempt !== $result->attempt) {
            throw new RuntimeException('STALE_WORKSPACE: owner workspace binding is stale for the submitted StageResult.');
        }
        $hasher = new ExecutionCandidateHasher($this->rootPath);
        if (!hash_equals($binding->workspaceIdentity, $hasher->workspaceIdentity($workspacePath))) {
            throw new RuntimeException('STALE_WORKSPACE: submitted workspace differs from the owner-bound workspace.');
        }
        $candidate = $hasher->candidateRevision($workspacePath, $binding->baseCommit);
        if (!hash_equals($result->candidateRevision, $candidate)) {
            throw new RuntimeException('STALE_WORKSPACE: StageResult candidate identity does not match owner-observed workspace state.');
        }

        return $candidate;
    }

    private function assertBindingIdentity(
        ExecutionWorkspaceBinding $binding,
        ExecutionPlan $plan,
        string $stageId,
        int $attempt,
        string $workspaceIdentity,
        string $initialCandidate,
    ): void {
        if ($binding->taskId !== $plan->taskId
            || $binding->runId !== $plan->runId
            || $binding->contractRevision !== $plan->contractRevision
            || !hash_equals($binding->executionPlanDigest, $plan->digest())
            || $binding->stageId !== $stageId
            || $binding->attempt !== $attempt
            || $binding->baseCommit !== $plan->baseCommit
            || !hash_equals($binding->workspaceIdentity, $workspaceIdentity)
            || !hash_equals($binding->initialCandidateRevision, $initialCandidate)) {
            throw new RuntimeException('STALE_WORKSPACE: stage attempt already has a different owner workspace binding.');
        }
    }

    private function path(string $taskId, string $stageId, int $attempt): string
    {
        return (new ProjectLayout($this->rootPath))->executionWorkspaceBindingPath($taskId, $stageId, $attempt);
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create owner workspace binding directory: ' . $directory);
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, CanonicalJson::pretty($data)) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('Unable to persist owner workspace binding: ' . $path);
        }
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Owner workspace binding field %s is invalid in %s.', $key, $path));
        }

        return trim($value);
    }
}
