<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class StageResult
{
    public const int MAX_OBSERVATIONS = 32;
    public const int MAX_OBSERVATION_BYTES = 2000;

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

    /** @var list<non-empty-string> */
    public array $consistencyReferences;

    public string $summary;
    public ?string $contextId;

    /**
     * Non-blocking findings a passing review stage reported. They are records,
     * not prose: finish routes them to Learning instead of letting a PASS
     * summary absorb them.
     *
     * @var list<non-empty-string>
     */
    public array $observations;

    /**
     * @param list<non-empty-string> $artifactReferences
     * @param list<non-empty-string> $validationReferences
     * @param list<non-empty-string> $consistencyReferences
     * @param list<string> $observations
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
        ?string $contextId = null,
        array $consistencyReferences = [],
        array $observations = [],
    ) {
        $this->submissionId = trim($submissionId);
        $this->taskId = trim($taskId);
        $this->runId = trim($runId);
        $this->executionPlanDigest = trim($executionPlanDigest);
        $this->stageId = trim($stageId);
        $this->candidateRevision = trim($candidateRevision);
        $this->artifactReferences = self::normalizeReferences($artifactReferences, 'artifact');
        $this->validationReferences = self::normalizeReferences($validationReferences, 'validation');
        $this->consistencyReferences = self::normalizeReferences($consistencyReferences, 'consistency');
        $this->summary = trim($summary);
        $this->contextId = $contextId === null ? null : trim($contextId);
        $this->observations = self::normalizeObservations($observations);

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
        if ($this->contextId !== null
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@\/-]{0,255}$/D', $this->contextId) !== 1) {
            throw new InvalidArgumentException('Stage result context id must be a bounded opaque identifier.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $payload = [
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

        if ($this->contextId !== null) {
            $payload['context_id'] = $this->contextId;
        }
        if ($this->consistencyReferences !== []) {
            $payload['consistency_references'] = $this->consistencyReferences;
        }
        if ($this->observations !== []) {
            $payload['observations'] = $this->observations;
        }

        return $payload;
    }

    /**
     * @param list<string> $observations
     * @return list<non-empty-string>
     */
    private static function normalizeObservations(array $observations): array
    {
        $normalized = [];
        foreach ($observations as $observation) {
            $observation = trim($observation);
            if ($observation === '') {
                throw new InvalidArgumentException('Stage result observations must be non-empty strings.');
            }
            if (strlen($observation) > self::MAX_OBSERVATION_BYTES) {
                throw new InvalidArgumentException('Stage result observation exceeds ' . self::MAX_OBSERVATION_BYTES . ' bytes.');
            }
            $normalized[] = $observation;
        }
        if (count($normalized) > self::MAX_OBSERVATIONS) {
            throw new InvalidArgumentException('Stage result carries more than ' . self::MAX_OBSERVATIONS . ' observations.');
        }

        return $normalized;
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
