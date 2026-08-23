<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class ExecutionState
{
    /** @param list<AcceptedStageResult> $history */
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public ?string $currentStageId,
        public int $currentAttempt,
        public string $candidateRevision,
        public ?AttentionRequest $attention,
        public array $history,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'governed_execution_state',
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'current_stage_id' => $this->currentStageId,
            'current_attempt' => $this->currentAttempt,
            'candidate_revision' => $this->candidateRevision,
            'attention' => $this->attention?->toArray(),
            'history' => array_map(static fn (AcceptedStageResult $result): array => $result->toArray(), $this->history),
        ];
    }
}
