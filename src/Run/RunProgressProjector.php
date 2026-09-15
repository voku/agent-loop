<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

/**
 * Read-only workflow progress derived from the same current facts as Loop policy.
 *
 * This class never authorizes work and never invents a next action. It carries
 * the canonical RunPolicyEvaluator result beside an ordered presentation model.
 */
final readonly class RunProgressProjector
{
    public function projectManifest(RunManifest $manifest): RunProgressProjection
    {
        return $this->project($manifest->taskId, $manifest->mode, $manifest->references, $manifest->disagreements);
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     */
    public function project(string $taskId, string $mode, array $references, array $disagreements): RunProgressProjection
    {
        $policy = (new RunPolicyEvaluator())->evaluate($taskId, $mode, $references, $disagreements);
        if ($mode === 'ephemeral') {
            return new RunProgressProjection($taskId, $policy->state, $policy->nextAction, $policy->nextActionKind, []);
        }

        $complete = $policy->state === 'complete';
        $contractDone = $this->state($references, 'contract') === 'approved'
            && $this->state($references, 'approval') === 'current';
        $prepared = $contractDone
            && $mode === 'governed'
            && $this->runMatchesCurrentContract($references)
            && $this->state($references, 'session') === 'active'
            && $this->state($references, 'recall') === 'compiled';

        $executionState = $this->state($references, 'execution_contract');
        $executionApplicable = $executionState !== 'not_required';
        $executionDone = !$executionApplicable || $executionState === 'ready';
        $executionBlocked = in_array($executionState, ['blocked', 'rejected', 'invalid', 'stale'], true);

        $verificationState = $this->state($references, 'verification');
        $verificationGate = $references['verification']['gate'] ?? null;
        $validationObserved = $verificationGate === 'validation';
        $validationBlocked = $validationObserved && in_array($verificationState, ['failed', 'blocked', 'invalid'], true);

        $reviewState = $this->state($references, 'review');
        $reviewStarted = in_array($reviewState, ['invalid', 'stale', 'unacknowledged', 'ok', 'warn', 'fail'], true);
        $reviewDone = in_array($reviewState, ['ok', 'warn'], true);
        $implementationDone = $prepared && $executionDone
            && ($validationObserved || $reviewStarted || in_array($policy->state, ['ready_to_close', 'complete'], true));
        $validationDone = $implementationDone
            && ($reviewStarted || in_array($policy->state, ['ready_to_close', 'complete'], true));
        $recallOutcomesCurrent = $verificationGate === 'recall_outcomes' && !$complete;
        $learningDone = $this->state($references, 'learning') === 'decided';

        $steps = [
            $this->step('contract', 'Contract', $complete || $contractDone ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT, 'agent-loop', $this->reason($references, 'approval') ?? $this->reason($references, 'contract')),
            $this->step('preparation', 'Run preparation', $complete ? RunProgressStep::STATUS_DONE : (!$contractDone ? RunProgressStep::STATUS_PENDING : ($prepared ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT)), 'agent-loop', $this->reason($references, 'session') ?? $this->reason($references, 'recall')),
            $this->step('execution_context', 'Execution context', !$executionApplicable ? RunProgressStep::STATUS_NOT_APPLICABLE : ($complete ? RunProgressStep::STATUS_DONE : (!$prepared ? RunProgressStep::STATUS_PENDING : ($executionDone ? RunProgressStep::STATUS_DONE : ($executionBlocked ? RunProgressStep::STATUS_BLOCKED : RunProgressStep::STATUS_CURRENT)))), $this->owner($references, 'execution_contract', 'agent-loop'), $this->reason($references, 'execution_contract')),
            $this->step('implementation', 'Implementation', $complete ? RunProgressStep::STATUS_DONE : (!$prepared || !$executionDone ? RunProgressStep::STATUS_PENDING : ($implementationDone ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT)), 'host'),
            $this->step('validation', 'Validation', $complete ? RunProgressStep::STATUS_DONE : (!$implementationDone ? RunProgressStep::STATUS_PENDING : ($validationDone ? RunProgressStep::STATUS_DONE : ($validationBlocked ? RunProgressStep::STATUS_BLOCKED : RunProgressStep::STATUS_CURRENT))), $this->owner($references, 'verification', 'agent-loop'), $validationObserved ? $this->reason($references, 'verification') : null),
            $this->step('review', 'Review', $complete ? RunProgressStep::STATUS_DONE : (!$validationDone ? RunProgressStep::STATUS_PENDING : ($reviewDone ? RunProgressStep::STATUS_DONE : ($reviewState === 'fail' ? RunProgressStep::STATUS_BLOCKED : RunProgressStep::STATUS_CURRENT))), $this->owner($references, 'review', 'agent-recall-compiler'), $this->reason($references, 'review')),
            $this->step('recall_outcomes', 'Recall outcomes', $recallOutcomesCurrent ? RunProgressStep::STATUS_CURRENT : RunProgressStep::STATUS_NOT_APPLICABLE, 'agent-recall-compiler', $recallOutcomesCurrent ? $this->reason($references, 'verification') : null),
            $this->step('learning', 'Learning', $complete ? RunProgressStep::STATUS_DONE : (!$reviewDone || $recallOutcomesCurrent ? RunProgressStep::STATUS_PENDING : ($learningDone ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT)), $this->owner($references, 'learning', 'agent-learning'), $this->reason($references, 'learning')),
            $this->step('closeout', 'Closeout', $complete ? RunProgressStep::STATUS_DONE : (!$learningDone ? RunProgressStep::STATUS_PENDING : (in_array($verificationState, ['failed', 'blocked', 'invalid'], true) ? RunProgressStep::STATUS_BLOCKED : RunProgressStep::STATUS_CURRENT)), 'agent-loop', $learningDone ? $this->reason($references, 'verification') : null),
        ];

        if ($disagreements !== []) {
            $first = $disagreements[0];
            foreach ($steps as $index => $step) {
                if ($step->status === RunProgressStep::STATUS_CURRENT) {
                    $steps[$index] = $this->step($step->id, $step->label, RunProgressStep::STATUS_BLOCKED, $first['owner'], $first['message']);
                    break;
                }
            }
        }

        return new RunProgressProjection($taskId, $policy->state, $policy->nextAction, $policy->nextActionKind, $steps);
    }

    private function step(string $id, string $label, string $status, string $owner, ?string $reason = null): RunProgressStep
    {
        return new RunProgressStep($id, $label, $status, $owner, $reason);
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
    private function state(array $references, string $name): ?string
    {
        $state = $references[$name]['state'] ?? null;

        return is_string($state) ? $state : null;
    }

    /** @param array<string, array<string, mixed>> $references */
    private function reason(array $references, string $name): ?string
    {
        $reason = $references[$name]['reason'] ?? null;

        return is_string($reason) && $reason !== '' ? $reason : null;
    }

    /** @param array<string, array<string, mixed>> $references */
    private function owner(array $references, string $name, string $fallback): string
    {
        $owner = $references[$name]['owner'] ?? null;

        return is_string($owner) && $owner !== '' ? $owner : $fallback;
    }
}
