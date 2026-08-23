<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class StageResult
{
    /**
     * @param list<non-empty-string> $artifactReferences
     * @param list<non-empty-string> $validationReferences
     */
    public function __construct(
        public string $submissionId,
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public string $stageId,
        public int $attempt,
        public StageOutcome $outcome,
        public string $candidateRevision,
        public array $artifactReferences,
        public array $validationReferences,
        public string $summary,
    ) {
        if (trim($this->submissionId) === '' || trim($this->taskId) === '' || trim($this->runId) === '') {
            throw new InvalidArgumentException('Stage result requires submission, task, and Run ids.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Stage result requires positive Contract revision and attempt.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Stage result requires an execution-plan sha256 digest.');
        }
        if (trim($this->stageId) === '' || trim($this->candidateRevision) === '') {
            throw new InvalidArgumentException('Stage result requires stage and candidate revision.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'submission_id' => $this->submissionId,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'outcome' => $this->outcome->value,
            'candidate_revision' => $this->candidateRevision,
            'artifact_references' => $this->artifactReferences,
            'validation_references' => $this->validationReferences,
            'summary' => $this->summary,
        ];
    }
}
