<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

/**
 * Pure lifecycle policy result derived from already-observed owner facts.
 *
 * This object owns no lifecycle state and performs no reconciliation. It only
 * answers what the current facts authorize and which action is decisive next.
 */
final readonly class RunPolicyEvaluation
{
    /** @param list<array{code: string, owner: string, message: string}> $blockers */
    public function __construct(
        public string $state,
        public bool $mutationAllowed,
        public bool $ordinaryCloseAllowed,
        public array $blockers,
        public string $nextAction,
    ) {
    }
}
