<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

/** @internal Owner-side evidence value; external hosts submit only StageResult references. */
final readonly class ExecutionEvidenceClaim
{
    public string $taskId;
    public string $runId;
    public string $executionPlanDigest;
    public string $stageId;
    public string $candidateRevision;
    public string $sourceReference;
    public string $sourceDigest;

    public function __construct(
        string $taskId,
        string $runId,
        public int $contractRevision,
        string $executionPlanDigest,
        string $stageId,
        public int $attempt,
        string $candidateRevision,
        public ExecutionEvidenceKind $kind,
        string $sourceReference,
        string $sourceDigest,
    ) {
        $this->taskId = trim($taskId);
        $this->runId = trim($runId);
        $this->executionPlanDigest = trim($executionPlanDigest);
        $this->stageId = trim($stageId);
        $this->candidateRevision = trim($candidateRevision);
        $this->sourceReference = trim($sourceReference);
        $this->sourceDigest = trim($sourceDigest);

        if ($this->taskId === '' || $this->runId === '' || $this->stageId === '' || $this->candidateRevision === '' || $this->sourceReference === '') {
            throw new InvalidArgumentException('Execution evidence claim requires non-empty task, Run, stage, candidate, and source references.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Execution evidence claim requires positive Contract revision and attempt.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Execution evidence claim requires an execution-plan sha256 digest.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->sourceDigest) !== 1) {
            throw new InvalidArgumentException('Execution evidence claim requires a source sha256 digest.');
        }
    }

    /**
     * @return array{
     *     task_id: string,
     *     run_id: string,
     *     contract_revision: int,
     *     execution_plan_digest: string,
     *     stage_id: string,
     *     attempt: int,
     *     candidate_revision: string,
     *     evidence_kind: string,
     *     source_reference: string,
     *     source_digest: string
     * }
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'candidate_revision' => $this->candidateRevision,
            'evidence_kind' => $this->kind->value,
            'source_reference' => $this->sourceReference,
            'source_digest' => $this->sourceDigest,
        ];
    }
}
