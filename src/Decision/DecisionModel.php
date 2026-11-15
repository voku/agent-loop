<?php

declare(strict_types=1);

namespace voku\AgentLoop\Decision;

/**
 * Advisory choice interface. Implementations cannot mutate workflow state.
 */
interface DecisionModel
{
    /**
     * @param non-empty-array<string, non-empty-string> $criteria
     *
     * @throws \RuntimeException on provider unavailability
     * @throws \UnexpectedValueException on an invalid response
     */
    public function decide(string $publicState, array $criteria, ?string $model): DecisionChoiceResult;
}
