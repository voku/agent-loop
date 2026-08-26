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

    public string $state;

    public bool $mutationAllowed;

    public bool $ordinaryCloseAllowed;

    /** @var list<array{code: string, owner: string, message: string}> */
    public array $blockers;

    public string $nextAction;

    public string $nextActionKind;

    /** @param list<array{code: string, owner: string, message: string}> $blockers */
    public function __construct(
        string $state,
        bool $mutationAllowed,
        bool $ordinaryCloseAllowed,
        array $blockers,
        string $nextAction,
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
        string $nextActionKind = self::KIND_COMMAND,
    ) {
        $this->state = $state;
        $this->mutationAllowed = $mutationAllowed;
        $this->ordinaryCloseAllowed = $ordinaryCloseAllowed;
        $this->blockers = $blockers;
        $this->nextAction = $nextAction;
        $this->nextActionKind = self::normalizeNextActionKind($nextAction, $nextActionKind);
    }

    private static function normalizeNextActionKind(string $nextAction, string $nextActionKind): string
    {
        if ($nextActionKind !== self::KIND_DECISION_REQUIRED) {
            return $nextActionKind;
        }

        if (
            str_starts_with($nextAction, 'agent-loop workflow plan ')
            && str_contains($nextAction, '--file <path>')
            && str_contains($nextAction, '--goal <goal>')
            && str_contains($nextAction, '--validation <validation>')
        ) {
            return self::KIND_COMMAND_TEMPLATE;
        }

        if (
            str_starts_with($nextAction, 'agent-loop workflow contract ')
            && str_contains($nextAction, '--status ready --from <l1.md>')
        ) {
            return self::KIND_COMMAND_TEMPLATE;
        }

        return $nextActionKind;
    }
}
