<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

final readonly class BlindSpotFinding
{
    /**
     * @param list<string> $evidence
     */
    public function __construct(
        public string $id,
        public ReviewSeverity $severity,
        public string $message,
        public array $evidence,
    ) {
    }

    /**
     * @return array{id: string, severity: string, message: string, evidence: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity->value,
            'message' => $this->message,
            'evidence' => $this->evidence,
        ];
    }
}
