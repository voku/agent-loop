<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

/**
 * Non-authoritative runtime artifact observation submitted by an external executor.
 *
 * agent-loop validates the exact current execution binding before converting this
 * observation into an owner-side evidence reference. This type cannot mint
 * validation truth.
 */
final readonly class StageArtifactObservation
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
            throw new InvalidArgumentException('Stage artifact observation requires non-empty execution, candidate, and source identity.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Stage artifact observation requires positive Contract revision and attempt.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Stage artifact observation requires an execution-plan sha256 digest.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->sourceDigest) !== 1) {
            throw new InvalidArgumentException('Stage artifact observation requires a source sha256 digest.');
        }
    }
}
