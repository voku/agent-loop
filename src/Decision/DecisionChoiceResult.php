<?php

declare(strict_types=1);

namespace voku\AgentLoop\Decision;

final readonly class DecisionChoiceResult
{
    public function __construct(
        public ?string $candidateId,
        public ?float $answerProbability,
    ) {
    }
}
