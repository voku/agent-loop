<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

/**
 * Non-authoritative candidate observation calculated by an external executor.
 *
 * The observation is useful only after agent-loop verifies the exact current
 * Run/Contract/plan/stage/attempt binding and converts it into owner evidence.
 */
final readonly class StageCandidateObservation
{
    public string $taskId;
    public string $runId;
    public string $executionPlanDigest;
    public string $stageId;
    public string $previousCandidateRevision;
    public string $candidateRevision;

    public function __construct(
        string $taskId,
        string $runId,
        public int $contractRevision,
        string $executionPlanDigest,
        string $stageId,
        public int $attempt,
        string $previousCandidateRevision,
        string $candidateRevision,
    ) {
        $this->taskId = trim($taskId);
        $this->runId = trim($runId);
        $this->executionPlanDigest = trim($executionPlanDigest);
        $this->stageId = trim($stageId);
        $this->previousCandidateRevision = trim($previousCandidateRevision);
        $this->candidateRevision = trim($candidateRevision);

        if ($this->taskId === ''
            || $this->runId === ''
            || $this->stageId === ''
            || $this->previousCandidateRevision === ''
            || $this->candidateRevision === '') {
            throw new InvalidArgumentException('Stage candidate observation requires non-empty execution and candidate identity.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Stage candidate observation requires positive Contract revision and attempt.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Stage candidate observation requires an execution-plan sha256 digest.');
        }
    }
}
