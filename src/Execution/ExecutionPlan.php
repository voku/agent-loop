<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Run\CanonicalJson;

final readonly class ExecutionPlan
{
    /**
     * @param array{path: non-empty-string, sha256: non-empty-string} $contractSource
     * @param list<ExecutionRole> $roles
     * @param list<ExecutionStage> $stages
     */
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public array $contractSource,
        public ?string $baseCommit,
        public ExecutionProfileName $profile,
        public array $roles,
        public array $stages,
        public string $preparedAt,
    ) {
        if ($this->taskId === '' || $this->runId === '' || $this->contractRevision < 1) {
            throw new InvalidArgumentException('Execution plan requires task, Run, and positive Contract revision.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $this->contractSource['sha256']) !== 1) {
            throw new InvalidArgumentException('Execution plan requires a sha256 Contract source binding.');
        }

        $roles = [];
        foreach ($this->roles as $role) {
            if (isset($roles[$role->id])) {
                throw new InvalidArgumentException('Duplicate execution role id: ' . $role->id);
            }
            $roles[$role->id] = $role;
        }

        $stages = [];
        foreach ($this->stages as $stage) {
            if (isset($stages[$stage->id])) {
                throw new InvalidArgumentException('Duplicate execution stage id: ' . $stage->id);
            }
            if ($stage->roleId !== null && !isset($roles[$stage->roleId])) {
                throw new InvalidArgumentException('Execution stage references unknown role: ' . $stage->roleId);
            }
            $stages[$stage->id] = $stage;
        }
        foreach ($this->stages as $stage) {
            foreach ($stage->requires as $required) {
                if (!isset($stages[$required])) {
                    throw new InvalidArgumentException(sprintf('Execution stage %s requires unknown stage %s.', $stage->id, $required));
                }
            }
            foreach ($stage->transitions as $next) {
                if ($next !== null && !isset($stages[$next])) {
                    throw new InvalidArgumentException(sprintf('Execution stage %s transitions to unknown stage %s.', $stage->id, $next));
                }
            }
        }
    }

    public static function resolve(
        ExecutionProfile $profile,
        string $taskId,
        string $runId,
        int $contractRevision,
        array $contractSource,
        ?string $baseCommit,
        string $preparedAt,
    ): self {
        return new self(
            $taskId,
            $runId,
            $contractRevision,
            $contractSource,
            $baseCommit,
            $profile->name,
            $profile->roles,
            $profile->stages,
            $preparedAt,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload() + ['digest' => $this->digest()];
    }

    public function digest(): string
    {
        return 'sha256:' . hash('sha256', CanonicalJson::pretty($this->payload()));
    }

    public function firstStageId(): ?string
    {
        return $this->stages[0]->id ?? null;
    }

    public function stage(string $stageId): ExecutionStage
    {
        foreach ($this->stages as $stage) {
            if ($stage->id === $stageId) {
                return $stage;
            }
        }

        throw new RuntimeException('Execution plan has no stage ' . $stageId . '.');
    }

    public function role(string $roleId): ExecutionRole
    {
        foreach ($this->roles as $role) {
            if ($role->id === $roleId) {
                return $role;
            }
        }

        throw new RuntimeException('Execution plan has no role ' . $roleId . '.');
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'governed_execution_plan',
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'contract_source' => $this->contractSource,
            'base_commit' => $this->baseCommit,
            'profile' => $this->profile->value,
            'roles' => array_map(static fn (ExecutionRole $role): array => $role->toArray(), $this->roles),
            'stages' => array_map(static fn (ExecutionStage $stage): array => $stage->toArray(), $this->stages),
            'prepared_at' => $this->preparedAt,
        ];
    }
}
