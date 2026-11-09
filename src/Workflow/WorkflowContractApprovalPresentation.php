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
            'operating_prompt_manifest' => $this->contract->operatingPromptManifest,
            'operating_prompts' => $this->contract->operatingPrompts,
            'revision_delta' => $this->revisionDelta(),
            'contract_path' => $contractPath,
        ];
    }

    /**
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $prompts
     * @return list<string>
     */
    public function operatingPromptSummaries(array $prompts): array
    {
        $summaries = [];
        foreach ($prompts as $prompt) {
            $arguments = $prompt['arguments'];
            ksort($arguments, SORT_STRING);
            $summaries[] = $prompt['id'] . ($arguments === [] ? '' : ' ' . json_encode(
                $arguments,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        }

        return $summaries;
    }

    /**
     * @return array{
     *     from_revision: int,
     *     to_revision: int,
     *     previous_was_approved: bool,
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

        $lists = $this->listChanges($this->previousRevision);

        $previousGoal = $this->previousRevision->goal === $this->contract->goal
            ? null
            : $this->previousRevision->goal;

        $wasApproved = $this->previousRevision->hasRecordedNonReservedApproval();

        return [
            'from_revision' => $this->previousRevision->revision,
            'to_revision' => $this->contract->revision,
            'previous_was_approved' => $wasApproved,
            'previous_approved_by' => $wasApproved ? $this->previousRevision->approvedBy : null,
            'previous_approved_at' => $wasApproved ? $this->previousRevision->approvedAt : null,
            'previous_goal' => $previousGoal,
            'lists' => $lists,
            'empty' => $previousGoal === null && $lists === [],
        ];
    }
    /**
     * @return array<string, array{added: list<string>, removed: list<string>}>
     */
    private function listChanges(TaskContract $previous): array
    {
        $beforePrompts = $this->operatingPromptSummaries($previous->operatingPrompts);
        $afterPrompts = $this->operatingPromptSummaries($this->contract->operatingPrompts);
        $comparisons = [
            'Scope' => [$previous->scope, $this->contract->scope],
            'Validation' => [$previous->validation, $this->contract->validation],
            'Non-goals' => [$previous->nonGoals, $this->contract->nonGoals],
            'Acceptance criteria' => [$previous->acceptanceCriteria, $this->contract->acceptanceCriteria],
            'Behavior anchors' => [$previous->behaviorAnchors, $this->contract->behaviorAnchors],
            'Operating prompt manifest' => [
                $previous->operatingPromptManifest === null ? [] : [$previous->operatingPromptManifest],
                $this->contract->operatingPromptManifest === null ? [] : [$this->contract->operatingPromptManifest],
            ],
            'Operating prompts' => [$beforePrompts, $afterPrompts],
        ];

        $changes = [];
        foreach ($comparisons as $label => [$before, $after]) {
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added !== [] || $removed !== []) {
                $changes[$label] = ['added' => $added, 'removed' => $removed];
            }
        }

        $orderChange = $this->promptOrderChange($previous->operatingPrompts, $this->contract->operatingPrompts, $beforePrompts, $afterPrompts);
        if ($orderChange !== null) {
            $changes['Operating prompt order'] = $orderChange;
        }

        return $changes;
    }

    /**
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $beforePrompts
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $afterPrompts
     * @param list<string> $before
     * @param list<string> $after
     * @return array{added: list<string>, removed: list<string>}|null
     */
    private function promptOrderChange(array $beforePrompts, array $afterPrompts, array $before, array $after): ?array
    {
        if ($before === $after) {
            return null;
        }

        $beforeIds = array_column($beforePrompts, 'id');
        $afterIds = array_column($afterPrompts, 'id');

        // Added/removed or reconfigured prompts must not mask reordered shared prompts.
        $sharedBefore = array_values(array_intersect($beforeIds, $afterIds));
        $sharedAfter = array_values(array_intersect($afterIds, $beforeIds));
        if ($sharedBefore === $sharedAfter) {
            return null;
        }

        return [
            'added' => [implode(' -> ', $after)],
            'removed' => [implode(' -> ', $before)],
        ];
    }
}
