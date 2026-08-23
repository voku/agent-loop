<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class ExecutionProfileSelection
{
    /** @param array{path: non-empty-string, sha256: non-empty-string} $contractSource */
    public function __construct(
        public string $taskId,
        public int $contractRevision,
        public array $contractSource,
        public ExecutionProfileName $profile,
        public string $selectedBy,
        public string $selectedAt,
        public string $path,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'execution_profile_selection',
            'task_id' => $this->taskId,
            'contract_revision' => $this->contractRevision,
            'contract_source' => $this->contractSource,
            'profile' => $this->profile->value,
            'selected_by' => $this->selectedBy,
            'selected_at' => $this->selectedAt,
        ];
    }
}
