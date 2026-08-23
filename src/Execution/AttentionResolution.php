<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class AttentionResolution
{
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public string $attentionId,
        public ?string $stageId,
        public string $resolvedBy,
        public string $resolvedAt,
    ) {
        if ($this->taskId === '' || $this->runId === '' || $this->attentionId === '' || $this->resolvedBy === '' || $this->resolvedAt === '') {
            throw new InvalidArgumentException('Attention resolution requires non-empty identity, actor, and timestamp fields.');
        }
        if ($this->contractRevision < 1) {
            throw new InvalidArgumentException('Attention resolution requires a positive Contract revision.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Attention resolution requires an execution-plan sha256 digest.');
        }
    }

    /** @return array{schema_version:string,kind:string,task_id:string,run_id:string,contract_revision:int,execution_plan_digest:string,attention_id:string,stage_id:string|null,resolved_by:string,resolved_at:string} */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'execution_attention_resolution',
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'attention_id' => $this->attentionId,
            'stage_id' => $this->stageId,
            'resolved_by' => $this->resolvedBy,
            'resolved_at' => $this->resolvedAt,
        ];
    }
}
