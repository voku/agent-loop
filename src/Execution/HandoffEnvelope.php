<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class HandoffEnvelope
{
    /**
     * @param list<non-empty-string> $artifactReferences
     * @param list<non-empty-string> $validationReferences
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $fromStage,
        public ?string $toStage,
        public string $candidateRevision,
        public string $executionPlanDigest,
        public array $artifactReferences,
        public array $validationReferences,
        public string $acceptedAt,
    ) {
        if ($this->contractRevision < 1) {
            throw new InvalidArgumentException('Handoff Contract revision must be positive.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Handoff requires an execution-plan sha256 digest.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'execution_handoff',
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'from_stage' => $this->fromStage,
            'to_stage' => $this->toStage,
            'candidate_revision' => $this->candidateRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'artifact_references' => $this->artifactReferences,
            'validation_references' => $this->validationReferences,
            'accepted_at' => $this->acceptedAt,
        ];
    }
}
