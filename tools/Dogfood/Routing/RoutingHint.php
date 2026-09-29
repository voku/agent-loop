<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood\Routing;

use InvalidArgumentException;

final readonly class RoutingHint
{
    /**
     * @param non-empty-list<string> $candidateIds
     */
    public function __construct(
        public string $candidateId,
        public float $probability,
        array $candidateIds,
    ) {
        if ($candidateId === '' || !in_array($candidateId, $candidateIds, true)) {
            throw new InvalidArgumentException('Routing hint candidate must be one of the managed subagent candidates.');
        }
        if (!is_finite($probability) || $probability < 0.0 || $probability > 1.0) {
            throw new InvalidArgumentException('Routing hint probability must be between 0.0 and 1.0.');
        }
    }

    /** @return array{candidate_id: string, probability: float} */
    public function toArray(): array
    {
        return [
            'candidate_id' => $this->candidateId,
            'probability' => $this->probability,
        ];
    }
}
