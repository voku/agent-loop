<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class AttentionRequest
{
    public function __construct(
        public string $id,
        public string $taskId,
        public string $runId,
        public AttentionKind $kind,
        public string $message,
        public ?string $stageId,
        public string $createdAt,
    ) {
    }

    /** @return array<string, string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'task_id' => $this->taskId,
            'run_id' => $this->runId,
            'kind' => $this->kind->value,
            'message' => $this->message,
            'stage_id' => $this->stageId,
            'created_at' => $this->createdAt,
        ];
    }
}
