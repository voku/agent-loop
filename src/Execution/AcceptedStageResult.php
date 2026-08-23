<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

final readonly class AcceptedStageResult
{
    public function __construct(
        public StageResult $result,
        public ?HandoffEnvelope $handoff,
        public string $acceptedAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'result' => $this->result->toArray(),
            'handoff' => $this->handoff?->toArray(),
            'accepted_at' => $this->acceptedAt,
        ];
    }
}
