<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class ExecutionProjection
{
    /** @param list<HandoffEnvelope> $handoffs */
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public ExecutionProfileName $profile,
        public string $executionPlanDigest,
        public ?string $currentStageId,
        public int $currentAttempt,
        public ?AttentionRequest $attention,
        public array $handoffs,
        public string $candidateRevision = '',
    ) {
    }

    public function complete(): bool
    {
        return $this->currentStageId === null && $this->attention === null;
    }
}
