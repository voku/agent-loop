<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class KanbanLaneSyncResult
{
    public function __construct(
        public string $taskId,
        public string $previousLane,
        public string $currentLane,
        public bool $mutated,
        public ?string $warning = null,
        public ?string $newRevision = null,
    ) {
    }
}
