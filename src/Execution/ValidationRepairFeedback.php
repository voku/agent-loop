<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

/** Selects the most recent failed validation for the unchanged candidate's ordinary repair stage. */
final class ValidationRepairFeedback
{
    public static function forRepairStage(ExecutionPlan $plan, ?ExecutionState $state, ?string $currentStage): ?string
    {
        if ($state === null || $state->history === [] || $currentStage === null) {
            return null;
        }

        foreach (array_reverse($state->history) as $accepted) {
            $result = $accepted->result;
            if ($result->candidateRevision !== $state->candidateRevision) {
                continue;
            }
            if (ExecutionProfile::isCandidateValidationStage($result->stageId)) {
                if ($result->outcome === StageOutcome::FAILED
                    && $plan->stage($result->stageId)->next(StageOutcome::FAILED) === $currentStage) {
                    return $result->summary;
                }

                return null;
            }
        }

        return null;
    }
}
