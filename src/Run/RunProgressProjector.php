<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

/**
 * Projects a presentation-safe workflow timeline from current owner facts.
 *
 * Lifecycle legality and routing remain owned by RunPolicyEvaluator. This class
 * deliberately consumes that policy result instead of creating a second next
 * action or mutation-authority decision.
 */
final readonly class RunProgressProjector
{
    public function projectManifest(RunManifest $manifest): RunProgressProjection
    {
        return $this->project(
            $manifest->taskId,
            $manifest->mode,
            $manifest->references,
            $manifest->disagreements,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     */
    public function project(string $taskId, string $mode, array $references, array $disagreements): RunProgressProjection
    {
        $policy = (new RunPolicyEvaluator())->evaluate($taskId, $mode, $references, $disagreements);
        if ($mode === 'ephemeral') {
            return new RunProgressProjection(
                $taskId,
                $policy->state,
                $policy->nextAction,
                $policy->nextActionKind,
                [],
            );
        }

        $contractDone = $this->referenceState($references, 'contract') === 'approved'
            && $this->referenceState($references, 'approval') === 'current';
        $prepared = $contractDone
            && $mode === 'governed'
            && $this->runMatchesCurrentContract($references)
            && $this->referenceState($references, 'session') === 'active'
            && $this->referenceState($references, 'recall') === 'compiled';

        $executionState = $this->referenceState($references, 'execution_contract');
        $executionApplicable = $executionState !== 'not_required';
        $executionDone = !$executionApplicable || $executionState === 'ready';
        $executionBlocked = in_array($executionState, ['blocked', 'rejected', 'invalid', 'stale'], true);

        $verificationState = $this->referenceState($references, 'verification');
        $verificationGate = $references['verification']['gate'] ?? null;
        $validationObserved = $verificationGate === 'validation';
        $validationBlocked = $validationObserved
            && in_array($verificationState, ['failed', 'blocked', 'invalid'], true);

        $reviewState = $this->referenceState($references, 'review');
        $reviewStarted = in_array($reviewState, ['invalid', 'stale', 'unacknowledged', 'ok', 'warn', 'fail'], true);
        $reviewDone = in_array($reviewState, ['ok', 'warn'], true);
        $reviewBlocked = $reviewState === 'fail';

        $implementationDone = $prepared
            && $executionDone
            && ($validationObserved || $reviewStarted || in_array($policy->state, ['ready_to_close', 'complete'], true));
        $validationDone = $implementationDone
            && ($reviewStarted || in_array($policy->state, ['ready_to_close', 'complete'], true));

        $recallOutcomesCurrent = $verificationGate === 'recall_outcomes';
        $learningDone = $this->referenceState($references, 'learning') === 'decided';
        $complete = $policy->state === 'complete';

        $steps = [
            new RunProgressStep(
                'contract',
                'Contract',
                $complete || $contractDone ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT,
                'agent-loop',
                $this->referenceReason($references, 'approval') ?? $this->referenceReason($references, 'contract'),
            ),
            new RunProgressStep(
                'preparation',
                'Run preparation',
                !$contractDone
                    ? RunProgressStep::STATUS_PENDING
                    : ($complete || $prepared ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT),
                'agent-loop',
                $this->referenceReason($references, 'session') ?? $this->referenceReason($references, 'recall'),
            ),
            new RunProgressStep(
                'execution_context',
                'Execution context',
                !$executionApplicable
                    ? RunProgressStep::STATUS_NOT_APPLICABLE
                    : (!$prepared
                        ? RunProgressStep::STATUS_PENDING
                        : ($complete || $executionDone
                            ? RunProgressStep::STATUS_DONE
                            : ($executionBlocked ? RunProgressStep::STATUS_BLOCKED : RunProgressStep::STATUS_CURRENT))),
                $this->referenceOwner($references, 'execution_contract', 'agent-loop'),
                $this->referenceReason($references, 'execution_contract'),
            ),
            new RunProgressStep(
                'implementation',
                'Implementation',
                !$prepared || !$executionDone
                    ? RunProgressStep::STATUS_PENDING
                    : ($complete || $implementationDone ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT),
                'host',
            ),
            new RunProgressStep(
                'validation',
                'Validation',
                !$implementationDone
                    ? RunProgressStep::STATUS_PENDING
                    : ($complete || $validationDone
                        ? RunProgressStep::STATUS_DONE
                        : ($validationBlocked ? RunProgressStep::STATUS_BLOCKED : RunProgressStep::STATUS_CURRENT)),
                $this->referenceOwner($references, 'verification', 'agent-loop'),
                $validationObserved ? $this->referenceReason($references, 'verification') : null,
            ),
            new RunProgressStep(
                'review',
                'Review',
                !$validationDone
                    ? RunProgressStep::STATUS_PENDING
                    : ($complete || $reviewDone
                        ? RunProgressStep::STATUS_DONE
                        : ($reviewBlocked ? RunProgressStep::STATUS_BLOCKED : RunProgressStep::STATUS_CURRENT)),
                $this->referenceOwner($references, 'review', 'agent-recall-compiler'),
                $this->referenceReason($references, 'review'),
            ),
            new RunProgressStep(
                'recall_outcomes',
                'Recall outcomes',
                $recallOutcomesCurrent ? RunProgressStep::STATUS_CURRENT : RunProgressStep::STATUS_NOT_APPLICABLE,
                'agent-recall-compiler',
                $recallOutcomesCurrent ? $this->referenceReason($references, 'verification') : null,
            ),
            new RunProgressStep(
                'learning',
                'Learning',
                !$reviewDone || $recallOutcomesCurrent
                    ? RunProgressStep::STATUS_PENDING
                    : ($complete || $learningDone ? RunProgressStep::STATUS_DONE : RunProgressStep::STATUS_CURRENT),
                $this->referenceOwner($references, 'learning', 'agent-learning'),
                $this->referenceReason($references, 'learning'),
            ),
            new RunProgressStep(
                'closeout',
                'Closeout',
                !$learningDone
                    ? RunProgressStep::STATUS_PENDING
                    : ($complete
                        ? RunProgressStep::STATUS_DONE
                        : (in_array($verificationState, ['failed', 'blocked', 'invalid'], true)
                            ? RunProgressStep::STATUS_BLOCKED
                            : RunProgressStep::STATUS_CURRENT)),
                'agent-loop',
                $learningDone ? $this->referenceReason($references, 'verification') : null,
            ),
        ];

        if ($disagreements !== []) {
            $first = $disagreements[0];
            foreach ($steps as $index => $step) {
                if ($step->status !== RunProgressStep::STATUS_CURRENT) {
                    continue;
                }

                $steps[$index] = new RunProgressStep(
                    $step->id,
                    $step->label,
                    RunProgressStep::STATUS_BLOCKED,
                    $first['owner'],
                    $first['message'],
                );
                break;
            }
        }

        return new RunProgressProjection(
            $taskId,
            $policy->state,
            $policy->nextAction,
            $policy->nextActionKind,
            $steps,
        );
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
    private function referenceState(array $references, string $name): ?string
    {
        $state = $references[$name]['state'] ?? null;

        return is_string($state) ? $state : null;
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
