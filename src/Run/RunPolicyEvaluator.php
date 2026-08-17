<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

/**
 * Pure lifecycle policy over already-observed owner facts.
 *
 * Fact acquisition, reconciliation, cache refreshes and mutations deliberately
 * live outside this class. Identical inputs therefore produce identical policy.
 */
final readonly class RunPolicyEvaluator
{
    /**
     * @param 'ephemeral'|'governed'|'planned'|'legacy_inferred' $mode
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    public function evaluate(string $taskId, string $mode, array $references, array $disagreements): RunPolicyEvaluation
    {
        $state = $this->state($mode, $references, $disagreements);

        return new RunPolicyEvaluation(
            $state,
            $this->mutationAllowed($state, $mode, $references, $disagreements),
            $state === 'ready_to_close',
            $this->blockers($state, $references, $disagreements),
            $this->nextAction($taskId, $mode, $references, $disagreements),
        );
    }

    /**
     * @param 'ephemeral'|'governed'|'planned'|'legacy_inferred' $mode
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     * @return 'blocked'|'experiment'|'incomplete'|'ready_to_close'|'complete'
     */
    private function state(string $mode, array $references, array $disagreements): string
    {
        if ($disagreements !== []) {
            return 'blocked';
        }
        if ($mode === 'ephemeral') {
            return 'experiment';
        }
        if ($mode !== 'governed' || $this->referenceState($references, 'contract') !== 'approved') {
            return 'incomplete';
        }
        if ($this->referenceState($references, 'recall') !== 'compiled') {
            return 'incomplete';
        }
        if (in_array($this->referenceState($references, 'execution_contract'), ['blocked', 'rejected', 'invalid', 'stale'], true)) {
            return 'blocked';
        }
        if ($this->referenceState($references, 'review') === 'fail') {
            return 'blocked';
        }
        if (in_array($this->referenceState($references, 'review'), ['missing', 'invalid'], true)) {
            return 'incomplete';
        }
        if ($this->referenceState($references, 'learning') !== 'decided') {
            return 'incomplete';
        }

        $verificationState = $this->referenceState($references, 'verification');
        if (in_array($verificationState, ['failed', 'blocked', 'invalid'], true)) {
            return 'blocked';
        }
        if (in_array($verificationState, ['passed', 'accepted_risk'], true)) {
            return $this->sessionClosedOrMissing($references) ? 'complete' : 'ready_to_close';
        }
        if ($verificationState === 'ready') {
            return 'ready_to_close';
        }

        return 'incomplete';
    }

    /**
     * @param 'blocked'|'experiment'|'incomplete'|'ready_to_close'|'complete' $state
     * @param 'ephemeral'|'governed'|'planned'|'legacy_inferred' $mode
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function mutationAllowed(string $state, string $mode, array $references, array $disagreements): bool
    {
        if ($state !== 'incomplete' || $mode !== 'governed' || $disagreements !== []) {
            return false;
        }

        return $this->referenceState($references, 'contract') === 'approved'
            && $this->referenceState($references, 'approval') === 'current'
            && $this->referenceState($references, 'session') === 'active'
            && $this->referenceState($references, 'recall') === 'compiled'
            && in_array($this->referenceState($references, 'execution_contract'), ['ready', 'not_required'], true);
    }

    /**
     * @param 'ephemeral'|'governed'|'planned'|'legacy_inferred' $mode
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function nextAction(string $taskId, string $mode, array $references, array $disagreements): string
    {
        if ($disagreements !== []) {
            return 'agent-loop workflow manifest ' . $taskId . ' --format=json';
        }
        if ($mode === 'ephemeral') {
            $sessionId = $references['session']['session_id'] ?? null;

            return is_string($sessionId) && $sessionId !== ''
                ? 'agent-loop session close ' . $sessionId . ' --status dropped'
                : 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if ($this->referenceState($references, 'contract') === 'missing') {
            return 'agent-loop workflow plan ' . $taskId . ' --by <actor> --file <path> --goal "..." --validation "..."';
        }
        if ($this->referenceState($references, 'contract') !== 'approved' || $mode !== 'governed') {
            return 'agent-loop workflow approve ' . $taskId . ' --by <named-actor>';
        }
        if ($this->referenceState($references, 'recall') !== 'compiled') {
            return 'agent-loop workflow approve ' . $taskId . ' --by <named-actor>';
        }
        if (in_array($this->referenceState($references, 'execution_contract'), ['missing', 'pending_recall'], true)) {
            return 'agent-loop workflow contract ' . $taskId . ' --status ready --from <l1.md> --by <actor>';
        }
        if (in_array($this->referenceState($references, 'execution_contract'), ['blocked', 'rejected', 'invalid', 'stale'], true)) {
            return 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if (
            $this->referenceState($references, 'verification') === 'blocked'
            && ($references['verification']['gate'] ?? null) === 'validation'
        ) {
            return $this->referenceAction($references, 'verification')
                ?? 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if (in_array($this->referenceState($references, 'review'), ['missing', 'invalid', 'fail'], true)) {
            return 'agent-loop review blindspots ' . $taskId;
        }
        if ($this->referenceState($references, 'learning') !== 'decided') {
            return 'agent-loop workflow learn ' . $taskId . ' --status no_durable_learning --by <actor> --reason "..."';
        }
        if ($this->referenceState($references, 'verification') === 'blocked') {
            return $this->referenceAction($references, 'verification')
                ?? 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if ($this->referenceState($references, 'verification') === 'ready') {
            return 'agent-loop workflow close ' . $taskId . ' --status done';
        }
        if (in_array($this->referenceState($references, 'verification'), ['passed', 'accepted_risk'], true)) {
            return $this->sessionClosedOrMissing($references)
                ? 'none'
                : 'agent-loop workflow close ' . $taskId . ' --status done';
        }

        return 'agent-loop workflow status ' . $taskId . ' --format=json';
    }

    /**
     * @param 'blocked'|'experiment'|'incomplete'|'ready_to_close'|'complete' $state
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     * @return list<array{code: string, owner: string, message: string}>
     */
    private function blockers(string $state, array $references, array $disagreements): array
    {
        if ($disagreements !== []) {
            return $disagreements;
        }
        if ($state !== 'blocked') {
            return [];
        }

        $executionContractState = $this->referenceState($references, 'execution_contract');
        if (in_array($executionContractState, ['blocked', 'rejected', 'invalid', 'stale'], true)) {
            return [[
                'code' => 'execution_contract.' . $executionContractState,
                'owner' => $this->referenceOwner($references, 'execution_contract', 'agent-loop'),
                'message' => $this->referenceReason($references, 'execution_contract')
                    ?? 'Execution contract is ' . $executionContractState . '.',
            ]];
        }
        if ($this->referenceState($references, 'review') === 'fail') {
            return [[
                'code' => 'review.failed',
                'owner' => $this->referenceOwner($references, 'review', 'agent-recall-compiler'),
                'message' => 'Blind-spot review reported failure.',
            ]];
        }

        $verificationState = $this->referenceState($references, 'verification');
        if (in_array($verificationState, ['failed', 'blocked', 'invalid'], true)) {
            $gate = $references['verification']['gate'] ?? null;

            return [[
                'code' => 'verification.' . (is_string($gate) && $gate !== '' ? $gate : $verificationState),
                'owner' => $this->referenceOwner($references, 'verification', 'agent-loop'),
                'message' => $this->referenceReason($references, 'verification')
                    ?? 'Verification is ' . $verificationState . '.',
            ]];
        }

        return [[
            'code' => 'lifecycle.blocked',
            'owner' => 'agent-loop',
            'message' => 'Lifecycle policy is blocked by current owner facts.',
        ]];
    }

    /** @param array<string, array<string, mixed>> $references */
    private function sessionClosedOrMissing(array $references): bool
    {
        return in_array($this->referenceState($references, 'session'), ['missing', 'done', 'dropped'], true);
    }

    /** @param array<string, array<string, mixed>> $references */
    private function referenceState(array $references, string $name): ?string
    {
        $state = $references[$name]['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    /** @param array<string, array<string, mixed>> $references */
    private function referenceAction(array $references, string $name): ?string
    {
        $action = $references[$name]['action'] ?? null;

        return is_string($action) && $action !== '' ? $action : null;
    }

    /** @param array<string, array<string, mixed>> $references */
    private function referenceReason(array $references, string $name): ?string
    {
        $reason = $references[$name]['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /** @param array<string, array<string, mixed>> $references */
    private function referenceOwner(array $references, string $name, string $fallback): string
    {
        $owner = $references[$name]['owner'] ?? null;

        return is_string($owner) && $owner !== '' ? $owner : $fallback;
    }
}
