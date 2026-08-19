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
    public const string KIND_COMMAND = 'command';
    public const string KIND_HOST_WORK = 'host_work';
    public const string KIND_HUMAN_DECISION = 'human_decision';
    public const string KIND_NONE = 'none';

    /** @param list<array{code: string, owner: string, message: string}> $blockers */
    public function __construct(
        public string $state,
        public bool $mutationAllowed,
        public bool $ordinaryCloseAllowed,
        public array $blockers,
        public string $nextAction,
        /**
         * How the host must treat nextAction.
         *
         * command        - execute it as written
         * host_work      - irreducible model work; nextAction describes it and
         *                  is not a command, so executing it is always wrong
         * human_decision - authoritative input is still required before the
         *                  described command can be executed
         * none           - nothing further is required
         */
        public string $nextActionKind = self::KIND_COMMAND,
    ) {
    }
}
