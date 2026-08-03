<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

final readonly class EditRoutingDecision
{
    public function __construct(
        public ?string $selectedRunner,
        public string $reason,
    ) {
    }

    public function invokesExternalModel(): bool
    {
        return $this->selectedRunner === 'command';
    }
}
