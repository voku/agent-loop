<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Run\RunPolicyEvaluator;

/** Ordered read-only workflow projection derived from Loop-owned policy/facts. */
final readonly class WorkflowProgressProjector
{
    /** @var non-empty-list<array{id: string, label: string, owner: string}> */
    private const array STEPS = [
        ['id' => 'contract', 'label' => 'Contract', 'owner' => 'agent-loop'],
        ['id' => 'preparation', 'label' => 'Run context', 'owner' => 'agent-loop'],
        ['id' => 'execution_contract', 'label' => 'Execution contract', 'owner' => 'agent-loop'],
        ['id' => 'implementation', 'label' => 'Implementation', 'owner' => 'coding-host'],
        ['id' => 'validation', 'label' => 'Validation', 'owner' => 'agent-session'],
        ['id' => 'review', 'label' => 'Review', 'owner' => 'agent-recall-compiler'],
        ['id' => 'recall_outcomes', 'label' => 'Recall outcomes', 'owner' => 'agent-learning'],
        ['id' => 'learning', 'label' => 'Learning disposition', 'owner' => 'agent-learning'],
        ['id' => 'close_readiness', 'label' => 'Close readiness', 'owner' => 'agent-loop'],
        ['id' => 'close', 'label' => 'Close', 'owner' => 'agent-loop'],
    ];

    public function project(RunManifest $manifest): WorkflowProgress
    {
        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
        $current = $this->currentStep($manifest, $policy);
        $currentIndex = $current === null ? null : $this->indexOf($current);
        $steps = [];

        foreach (self::STEPS as $index => $definition) {
            $id = $definition['id'];
            $state = $this->stateFor($manifest, $policy, $id, $index, $currentIndex);
            $steps[] = new WorkflowProgressStep(
                $id,
                $definition['label'],
                $state,
                $this->ownerFor($manifest, $id, $definition['owner']),
                $this->evidenceStateFor($manifest, $id),
                $this->reasonFor($manifest, $policy, $id, $state),
            );
        }

        return new WorkflowProgress(
            $manifest->taskId,
            $policy->state,
            $steps,
            $current,
            $policy->nextActionKind,
            $policy->nextAction,
        );
    }

    private function currentStep(RunManifest $manifest, RunPolicyEvaluation $policy): ?string
    {
        if ($manifest->mode === 'ephemeral' || $policy->state === 'complete' || $policy->nextActionKind === RunPolicyEvaluation::KIND_NONE) {
            return null;
        }

        if ($manifest->disagreements !== []) {
            $code = $manifest->disagreements[0]['code'];

            return match (true) {
                str_starts_with($code, 'contract.'), str_starts_with($code, 'approval.') => 'contract',
                str_starts_with($code, 'execution_contract.') => 'execution_contract',
                str_starts_with($code, 'review.') => 'review',
                str_starts_with($code, 'learning.') => 'learning',
                str_starts_with($code, 'verification.') => 'close_readiness',
                default => 'preparation',
            };
        }

        $references = $manifest->references;
        $verificationState = $this->referenceState($references, 'verification');
        $verificationGate = $references['verification']['gate'] ?? null;

        if (in_array($this->referenceState($references, 'execution_contract'), ['blocked', 'rejected'], true)) {
            return 'execution_contract';
        }
        if ($verificationState === 'blocked' && $verificationGate === 'validation') {
            return 'validation';
        }
        if ($policy->mutationAllowed) {
            return 'implementation';
        }
        if ($this->referenceState($references, 'contract') !== 'approved' || $this->referenceState($references, 'approval') !== 'current') {
            return 'contract';
        }
        if (!$this->runMatchesCurrentContract($references) || $this->referenceState($references, 'session') !== 'active' || $this->referenceState($references, 'recall') !== 'compiled') {
            return 'preparation';
        }
        if (in_array($this->referenceState($references, 'execution_contract'), ['missing', 'pending_recall', 'invalid', 'stale'], true)) {
            return 'execution_contract';
        }
        if (in_array($this->referenceState($references, 'review'), ['missing', 'invalid', 'stale', 'unacknowledged', 'fail'], true)) {
            return 'review';
        }
        if ($verificationState === 'blocked' && $verificationGate === 'recall_outcomes') {
            return 'recall_outcomes';
        }
        if ($this->referenceState($references, 'learning') !== 'decided') {
            return 'learning';
        }
        if (in_array($verificationState, ['failed', 'blocked', 'invalid', 'ready'], true)) {
            return 'close_readiness';
        }
        if (in_array($verificationState, ['passed', 'accepted_risk'], true)) {
            return $this->sessionClosedOrMissing($references) ? null : 'close';
        }

        return 'preparation';
    }

    private function stateFor(RunManifest $manifest, RunPolicyEvaluation $policy, string $id, int $index, ?int $currentIndex): string
    {
        if ($manifest->mode === 'ephemeral') {
            return WorkflowProgressStep::STATE_NOT_APPLICABLE;
        }
        if ($id === 'execution_contract' && $this->referenceState($manifest->references, 'execution_contract') === 'not_required') {
            return WorkflowProgressStep::STATE_NOT_APPLICABLE;
        }
        if ($policy->state === 'complete') {
            return WorkflowProgressStep::STATE_DONE;
        }
        if ($currentIndex === null) {
            return WorkflowProgressStep::STATE_PENDING;
        }
        if ($index < $currentIndex) {
            return WorkflowProgressStep::STATE_DONE;
        }
        if ($index > $currentIndex) {
            return WorkflowProgressStep::STATE_PENDING;
        }

        return $this->currentStepIsBlocked($manifest, $policy, $id)
            ? WorkflowProgressStep::STATE_BLOCKED
            : WorkflowProgressStep::STATE_CURRENT;
    }

    private function currentStepIsBlocked(RunManifest $manifest, RunPolicyEvaluation $policy, string $id): bool
    {
        if ($policy->state === 'blocked') {
            return true;
        }

        $references = $manifest->references;

        return match ($id) {
            'execution_contract' => in_array(
                $this->referenceState($references, 'execution_contract'),
                ['blocked', 'rejected', 'invalid', 'stale'],
                true,
            ),
            'validation' => ($references['verification']['gate'] ?? null) === 'validation'
                && in_array($this->referenceState($references, 'verification'), ['blocked', 'failed', 'invalid'], true),
            'review' => in_array($this->referenceState($references, 'review'), ['fail', 'invalid', 'stale'], true),
            'recall_outcomes' => ($references['verification']['gate'] ?? null) === 'recall_outcomes'
                && in_array($this->referenceState($references, 'verification'), ['blocked', 'failed', 'invalid'], true),
            'close_readiness' => in_array($this->referenceState($references, 'verification'), ['blocked', 'failed', 'invalid'], true),
            default => false,
        };
    }

    private function reasonFor(
        RunManifest $manifest,
        RunPolicyEvaluation $policy,
        string $id,
        string $state,
    ): ?string {
        if ($state !== WorkflowProgressStep::STATE_BLOCKED) {
            return null;
        }
        if ($policy->blockers !== []) {
            return $policy->blockers[0]['message'];
        }

        $reference = match ($id) {
            'execution_contract' => 'execution_contract',
            'validation', 'recall_outcomes', 'close_readiness' => 'verification',
            'review' => 'review',
            default => null,
        };
        if ($reference === null) {
            return null;
        }

        $reason = $manifest->references[$reference]['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    private function ownerFor(RunManifest $manifest, string $id, string $fallback): string
    {
        $reference = match ($id) {
            'contract' => 'contract',
            'execution_contract' => 'execution_contract',
            'review' => 'review',
            'learning' => 'learning',
            'close_readiness' => 'verification',
            default => null,
        };
        $owner = $reference === null ? null : ($manifest->references[$reference]['owner'] ?? null);

        return is_string($owner) && $owner !== '' ? $owner : $fallback;
    }

    private function evidenceStateFor(RunManifest $manifest, string $id): ?string
    {
        $references = $manifest->references;
        $verificationState = $this->referenceState($references, 'verification');
        $verificationGate = $references['verification']['gate'] ?? null;

        return match ($id) {
            'contract' => $this->referenceState($references, 'contract'),
            'preparation' => $this->referenceState($references, 'approval') !== 'current'
                ? $this->referenceState($references, 'approval')
                : ($this->referenceState($references, 'session') !== 'active'
                    ? $this->referenceState($references, 'session')
                    : $this->referenceState($references, 'recall')),
            'execution_contract' => $this->referenceState($references, 'execution_contract'),
            'validation' => $verificationGate === 'validation' ? $verificationState : null,
            'review' => $this->referenceState($references, 'review'),
            'recall_outcomes' => $verificationGate === 'recall_outcomes' ? $verificationState : null,
            'learning' => $this->referenceState($references, 'learning'),
            'close_readiness' => $verificationState,
            'close' => $this->referenceState($references, 'session'),
            default => null,
        };
    }

    private function indexOf(string $id): int
    {
        foreach (self::STEPS as $index => $definition) {
            if ($definition['id'] === $id) {
                return $index;
            }
        }

        return 0;
    }

    /** @param array<string, array<string, mixed>> $references */
    private function runMatchesCurrentContract(array $references): bool
    {
        $contractRevision = $references['contract']['revision'] ?? null;
        $runRevision = $references['contract']['run_revision'] ?? null;

        return !is_int($contractRevision) || $runRevision === null || (is_int($runRevision) && $runRevision === $contractRevision);
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
}
