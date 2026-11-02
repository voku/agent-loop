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
            'Behavior anchors' => [$this->previousRevision->behaviorAnchors, $this->contract->behaviorAnchors],
            'Operating prompt manifest' => [
                $this->previousRevision->operatingPromptManifest === null ? [] : [$this->previousRevision->operatingPromptManifest],
                $this->contract->operatingPromptManifest === null ? [] : [$this->contract->operatingPromptManifest],
            ],
            'Operating prompts' => [
                $this->operatingPromptSummaries($this->previousRevision->operatingPrompts),
                $this->operatingPromptSummaries($this->contract->operatingPrompts),
            ],
        ] as $label => [$before, $after]) {
            $added = array_values(array_diff($after, $before));
            $removed = array_values(array_diff($before, $after));
            if ($added === [] && $removed === []) {
                continue;
            }

            $lists[$label] = ['added' => $added, 'removed' => $removed];
        }
        $beforePrompts = $this->operatingPromptSummaries($this->previousRevision->operatingPrompts);
        $afterPrompts = $this->operatingPromptSummaries($this->contract->operatingPrompts);
        if ($beforePrompts !== $afterPrompts && array_count_values($beforePrompts) == array_count_values($afterPrompts)) {
            // Identical prompts can still carry different sequential intent.
            $lists['Operating prompt order'] = [
                'added' => [implode(' -> ', $afterPrompts)],
                'removed' => [implode(' -> ', $beforePrompts)],
            ];
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
