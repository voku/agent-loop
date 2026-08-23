<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class StageResultEvidence
{
    /**
     * @param array<non-empty-string, non-empty-string> $artifactDigests
     * @param array<non-empty-string, int> $validationExitCodes
     */
    public function __construct(
        public string $submissionId,
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public string $stageId,
        public int $attempt,
        public string $candidateRevision,
        public string $workspaceIdentity,
        public array $artifactDigests,
        public array $validationExitCodes,
        public string $observedAt,
    ) {
        if ($this->submissionId === '' || $this->taskId === '' || $this->runId === '' || $this->stageId === '' || $this->candidateRevision === '' || $this->workspaceIdentity === '' || $this->observedAt === '') {
            throw new InvalidArgumentException('Stage result evidence requires non-empty identity fields.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Stage result evidence requires positive Contract revision and attempt.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Stage result evidence requires an execution-plan sha256 digest.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'authoritative_stage_result_evidence',
            'submission_id' => $this->submissionId,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'candidate_revision' => $this->candidateRevision,
            'workspace_identity' => $this->workspaceIdentity,
            'artifact_digests' => $this->artifactDigests,
            'validation_exit_codes' => $this->validationExitCodes,
            'observed_at' => $this->observedAt,
        ];
    }
}
