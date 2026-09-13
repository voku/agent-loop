<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class CurrentExecutionStageProjection
{
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public ?string $stageId,
        public int $attempt,
        public string $candidateRevision,
        public ?ExecutionStageKind $stageKind,
        public ?string $roleId,
    ) {
    }
}
