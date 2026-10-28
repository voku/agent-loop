<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

final readonly class RunRetirement
{
    public function __construct(
        public string $taskId,
        public string $runId,
        public int $contractRevision,
        public string $status,
        public string $reason,
        public string $retiredBy,
        public string $retiredAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'governed_run_retirement',
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'contract_revision' => $this->contractRevision,
            'status' => $this->status,
            'reason' => $this->reason,
            'retired_by' => $this->retiredBy,
            'retired_at' => $this->retiredAt,
        ];
    }
}
