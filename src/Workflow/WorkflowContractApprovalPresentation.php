<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

/**
 * Typed host projection of the candidate Contract a human is asked to approve.
 * The revision delta is mechanical evidence, not a judgment about the change.
 */
final readonly class WorkflowContractApprovalPresentation
{
    public function __construct(
        public TaskContract $contract,
        public ?TaskContract $previousRevision,
        public string $action,
    ) {
    }

    /** @return array<string, mixed> */
    public function subject(string $contractPath): array
    {
        return [
            'contract_revision' => $this->contract->revision,
            'goal' => $this->contract->goal,
            'scope' => $this->contract->scope,
            'non_goals' => $this->contract->nonGoals,
            'acceptance_criteria' => $this->contract->acceptanceCriteria,
            'validation' => $this->contract->validation,
            'behavior_anchors' => $this->contract->behaviorAnchors,
            'operating_prompts' => $this->contract->operatingPrompts,
            'revision_delta' => $this->revisionDelta(),
            'contract_path' => $contractPath,
        ];
    }

    /**
     * @return array{
     *     from_revision: int,
     *     to_revision: int,
     *     previous_approved_by: string|null,
     *     previous_approved_at: string|null,
     *     previous_goal: string|null,
     *     lists: array<string, array{added: list<string>, removed: list<string>}>,
     *     empty: bool
     * }|null
     */
    public function revisionDelta(): ?array
    {
        if ($this->previousRevision === null) {
            return null;
        }

        $lists = [];
        foreach ([
            'Scope' => [$this->previousRevision->scope, $this->contract->scope],
            'Validation' => [$this->previousRevision->validation, $this->contract->validation],
            'Non-goals' => [$this->previousRevision->nonGoals, $this->contract->nonGoals],
            'Acceptance criteria' => [$this->previousRevision->acceptanceCriteria, $this->contract->acceptanceCriteria],
        ] as $label => [$before, $after]) {
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added === [] && $removed === []) {
                continue;
            }

            $lists[$label] = ['added' => $added, 'removed' => $removed];
        }
        $previousGoal = $this->previousRevision->goal === $this->contract->goal
            ? null
            : $this->previousRevision->goal;

        return [
            'from_revision' => $this->previousRevision->revision,
            'to_revision' => $this->contract->revision,
            'previous_approved_by' => $this->previousRevision->approvedBy,
            'previous_approved_at' => $this->previousRevision->approvedAt,
            'previous_goal' => $previousGoal,
            'lists' => $lists,
            'empty' => $previousGoal === null && $lists === [],
        ];
    }
}
