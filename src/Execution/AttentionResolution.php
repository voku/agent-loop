<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class AttentionResolution
{
    public string $attentionId;
    public string $taskId;
    public string $runId;
    public string $executionPlanDigest;
    public string $stageId;
    public string $actor;
    public string $resolvedAt;

    public function __construct(
        string $attentionId,
        string $taskId,
        string $runId,
        public int $contractRevision,
        string $executionPlanDigest,
        string $stageId,
        public int $attempt,
        string $actor,
        string $resolvedAt,
    ) {
        $this->attentionId = trim($attentionId);
        $this->taskId = trim($taskId);
        $this->runId = trim($runId);
        $this->executionPlanDigest = trim($executionPlanDigest);
        $this->stageId = trim($stageId);
        $this->actor = trim($actor);
        $this->resolvedAt = trim($resolvedAt);

        if ($this->attentionId === '' || $this->taskId === '' || $this->runId === '' || $this->stageId === '' || $this->actor === '' || $this->resolvedAt === '') {
            throw new InvalidArgumentException('Attention resolution requires non-empty identity, binding, actor, and timestamp fields.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Attention resolution requires positive Contract revision and attempt.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Attention resolution requires an execution-plan sha256 digest.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'execution_attention_resolution',
            'attention_id' => $this->attentionId,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'actor' => $this->actor,
            'resolved_at' => $this->resolvedAt,
        ];
    }
}
