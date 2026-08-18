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
    public function evaluateManifest(RunManifest $manifest): RunPolicyEvaluation
    {
        return $this->evaluate(
            $manifest->taskId,
            $manifest->mode,
            $manifest->references,
            $manifest->disagreements,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    public function evaluate(string $taskId, string $mode, array $references, array $disagreements): RunPolicyEvaluation
    {
        $state = $this->state($mode, $references, $disagreements);

        return new RunPolicyEvaluation(
            $state,
            $this->mutationAllowed($state, $mode, $references, $disagreements),
            in_array($state, ['ready_to_close', 'complete'], true),
            $this->blockers($state, $references, $disagreements),
            $this->nextAction($taskId, $state, $mode, $references, $disagreements),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
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
        if (!$this->runMatchesCurrentContract($references)) {
            return 'incomplete';
        }
        if ($this->referenceState($references, 'recall') !== 'compiled') {
            return 'incomplete';
        }
        if (in_array($this->referenceState($references, 'execution_contract'), ['blocked', 'rejected', 'invalid', 'stale'], true)) {
            return 'blocked';
        }

        $reviewState = $this->referenceState($references, 'review');
        if ($reviewState === 'fail') {
            return 'blocked';
        }
        if (in_array($reviewState, ['missing', 'invalid', 'stale', 'unacknowledged'], true)) {
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
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function mutationAllowed(string $state, string $mode, array $references, array $disagreements): bool
    {
        if ($state !== 'incomplete' || $mode !== 'governed' || $disagreements !== []) {
            return false;
        }

        // Once the current review exists and is waiting for acknowledgement, or
        // has already been acknowledged, implementation work is no longer open.
        // A stale/missing review may still describe changed implementation and
        // therefore does not by itself revoke mutation authority.
        if (in_array($this->referenceState($references, 'review'), ['unacknowledged', 'ok', 'warn'], true)) {
            return false;
        }

        return $this->referenceState($references, 'contract') === 'approved'
            && $this->referenceState($references, 'approval') === 'current'
            && $this->runMatchesCurrentContract($references)
            && $this->referenceState($references, 'session') === 'active'
            && $this->referenceState($references, 'recall') === 'compiled'
            && in_array($this->referenceState($references, 'execution_contract'), ['ready', 'not_required'], true);
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function nextAction(
        string $taskId,
        string $state,
        string $mode,
        array $references,
        array $disagreements,
    ): string {
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
        if ($this->referenceState($references, 'contract') !== 'approved') {
            return 'agent-loop workflow approve ' . $taskId . ' --by <named-actor>';
        }
        if ($this->referenceState($references, 'approval') !== 'current') {
            return 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if (
            $state === 'incomplete'
            && (
                $mode !== 'governed'
                || !$this->runMatchesCurrentContract($references)
                || $this->referenceState($references, 'session') !== 'active'
                || $this->referenceState($references, 'recall') !== 'compiled'
            )
        ) {
            return 'agent-loop enter ' . $taskId;
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
            return 'agent-loop finish ' . $taskId;
        }

        $reviewState = $this->referenceState($references, 'review');
        if (in_array($reviewState, ['missing', 'invalid', 'stale'], true)) {
            return 'agent-loop finish ' . $taskId;
        }
        if ($reviewState === 'unacknowledged') {
            $digest = $this->reviewDigest($references);

            return $digest === null
                ? 'agent-loop workflow manifest ' . $taskId . ' --format=json'
                : 'agent-loop finish ' . $taskId . ' --reviewed-report-sha256 ' . $digest . ' --by <actor>';
        }
        if ($reviewState === 'fail') {
            return 'agent-loop review blindspots ' . $taskId;
        }
        if ($this->referenceState($references, 'learning') !== 'decided') {
            return 'agent-loop finish ' . $taskId
                . ' --learning <no_durable_learning|findings_recorded|follow_up_required> --learning-reason "..." --by <actor>';
        }
        if ($this->referenceState($references, 'verification') === 'blocked') {
            return $this->referenceAction($references, 'verification')
                ?? 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if ($this->referenceState($references, 'verification') === 'ready') {
            return 'agent-loop finish ' . $taskId;
        }
        if (in_array($this->referenceState($references, 'verification'), ['passed', 'accepted_risk'], true)) {
            return $this->sessionClosedOrMissing($references)
                ? 'none'
                : 'agent-loop finish ' . $taskId;
        }

        return 'agent-loop workflow status ' . $taskId . ' --format=json';
    }

    /**
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
    private function runMatchesCurrentContract(array $references): bool
    {
        $contractRevision = $references['contract']['revision'] ?? null;
        $runRevision = $references['contract']['run_revision'] ?? null;
        if (!is_int($contractRevision) || $runRevision === null) {
            return true;
        }

        return is_int($runRevision) && $runRevision === $contractRevision;
    }

    /** @param array<string, array<string, mixed>> $references */
    private function sessionClosedOrMissing(array $references): bool
    {
        return in_array($this->referenceState($references, 'session'), ['missing', 'done', 'dropped'], true);
    }

    /** @param array<string, array<string, mixed>> $references */
    private function reviewDigest(array $references): ?string
    {
        $source = $references['review']['source'] ?? null;
        $digest = is_array($source) ? ($source['sha256'] ?? null) : null;

        return is_string($digest) && preg_match('/^sha256:[a-f0-9]{64}$/', $digest) === 1 ? $digest : null;
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
