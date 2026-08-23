<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class StageResult
{
    public string $submissionId;
    public string $taskId;
    public string $runId;
    public string $executionPlanDigest;
    public string $stageId;
    public string $candidateRevision;

    /** @var list<non-empty-string> */
    public array $artifactReferences;

    /** @var list<non-empty-string> */
    public array $validationReferences;

    public string $summary;

    /**
     * @param list<non-empty-string> $artifactReferences
     * @param list<non-empty-string> $validationReferences
     */
    public function __construct(
        string $submissionId,
        string $taskId,
        string $runId,
        public int $contractRevision,
        string $executionPlanDigest,
        string $stageId,
        public int $attempt,
        public StageOutcome $outcome,
        string $candidateRevision,
        array $artifactReferences,
        array $validationReferences,
        string $summary,
    ) {
        $this->submissionId = trim($submissionId);
        $this->taskId = trim($taskId);
        $this->runId = trim($runId);
        $this->executionPlanDigest = trim($executionPlanDigest);
        $this->stageId = trim($stageId);
        $this->candidateRevision = trim($candidateRevision);
        $this->artifactReferences = self::normalizeReferences($artifactReferences, 'artifact');
        $this->validationReferences = self::normalizeReferences($validationReferences, 'validation');
        $this->summary = trim($summary);

        if ($this->submissionId === '' || $this->taskId === '' || $this->runId === '') {
            throw new InvalidArgumentException('Stage result requires submission, task, and Run ids.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Stage result requires positive Contract revision and attempt.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Stage result requires an execution-plan sha256 digest.');
        }
        if ($this->stageId === '' || $this->candidateRevision === '') {
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

    /**
     * @param list<non-empty-string> $references
     * @return list<non-empty-string>
     */
    private static function normalizeReferences(array $references, string $kind): array
    {
        $normalized = [];
        foreach ($references as $reference) {
            $reference = trim($reference);
            if ($reference === '') {
                throw new InvalidArgumentException('Stage result ' . $kind . ' references must be non-empty strings.');
            }
            $normalized[] = $reference;
        }

        return $normalized;
    }
}
