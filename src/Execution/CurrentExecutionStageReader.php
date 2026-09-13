<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use RuntimeException;

final readonly class CurrentExecutionStageReader
{
    public function __construct(private string $rootPath)
    {
    }

    public function read(string $taskId): CurrentExecutionStageProjection
    {
        $plan = (new ExecutionPlanStore($this->rootPath))->load($taskId);
        $state = (new ExecutionStateStore($this->rootPath))->find($taskId)
            ?? throw new RuntimeException('No execution state exists for task ' . $taskId . '.');

        if ($state->taskId !== $plan->taskId
            || $state->runId !== $plan->runId
            || $state->contractRevision !== $plan->contractRevision
            || !hash_equals($state->executionPlanDigest, $plan->digest())) {
            throw new RuntimeException('Execution state is stale for the current governed execution plan.');
        }

        if ($state->currentStageId === null) {
            return new CurrentExecutionStageProjection(
                $state->taskId,
                $state->runId,
                $state->contractRevision,
                $state->executionPlanDigest,
                null,
                $state->currentAttempt,
                $state->candidateRevision,
                null,
                null,
            );
        }

        $stage = $plan->stage($state->currentStageId);

        return new CurrentExecutionStageProjection(
            $state->taskId,
            $state->runId,
            $state->contractRevision,
            $state->executionPlanDigest,
            $stage->id,
            $state->currentAttempt,
            $state->candidateRevision,
            $stage->kind,
            $stage->roleId,
        );
    }
}
