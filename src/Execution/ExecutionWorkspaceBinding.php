<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class ExecutionWorkspaceBinding
{
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
        public string $stageId,
        public int $attempt,
        public string $baseCommit,
        public string $workspaceIdentity,
        public string $initialCandidateRevision,
        public string $boundAt,
    ) {
        if ($this->taskId === '' || $this->runId === '' || $this->stageId === '' || $this->workspaceIdentity === '' || $this->initialCandidateRevision === '' || $this->boundAt === '') {
            throw new InvalidArgumentException('Execution workspace binding requires non-empty identity fields.');
        }
        if ($this->contractRevision < 1 || $this->attempt < 1) {
            throw new InvalidArgumentException('Execution workspace binding requires positive Contract revision and attempt.');
        }
        if (preg_match('/^[0-9a-f]{40,64}$/', $this->baseCommit) !== 1 || preg_match('/^sha256:[a-f0-9]{64}$/', $this->executionPlanDigest) !== 1) {
            throw new InvalidArgumentException('Execution workspace binding requires exact base and plan digest provenance.');
        }
    }

    /** @return array{schema_version:string,kind:string,task_id:string,run_id:string,contract_revision:int,execution_plan_digest:string,stage_id:string,attempt:int,base_commit:string,workspace_identity:string,initial_candidate_revision:string,bound_at:string} */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'execution_workspace_binding',
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'execution_plan_digest' => $this->executionPlanDigest,
            'stage_id' => $this->stageId,
            'attempt' => $this->attempt,
            'base_commit' => $this->baseCommit,
            'workspace_identity' => $this->workspaceIdentity,
            'initial_candidate_revision' => $this->initialCandidateRevision,
            'bound_at' => $this->boundAt,
        ];
    }
}
