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
    public const string KIND_COMMAND_TEMPLATE = 'command_template';
    public const string KIND_HOST_WORK = 'host_work';
    public const string KIND_DECISION_REQUIRED = 'decision_required';
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
         * command           - execute it as written
         * command_template  - fill model-owned placeholders from the request and
         *                     repository evidence, then execute it without asking
         *                     a human merely because placeholders exist
         * host_work         - irreducible implementation/model work; nextAction
         *                     describes it and is not a command
         * decision_required - a genuine human-authority decision is required
         *                     before the action can be executed
         * none              - nothing further is required
         */
        public string $nextActionKind = self::KIND_COMMAND,
    ) {
    }
}
