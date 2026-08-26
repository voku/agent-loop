<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class TaskContract
{
    public const string CANDIDATE = 'candidate';
    public const string APPROVED = 'approved';
    public const string SUPERSEDED = 'superseded';

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags
     * @param list<string> $behaviorAnchors
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $operatingPrompts
     * @param list<string> $acceptanceCriteria Required outcomes from the approved task definition.
     *        Their presence is not evidence that they are satisfied.
     * @param list<array{acceptance: string, validations: list<string>}> $acceptanceObservations
     *        Declared observation coverage for acceptance criteria. This is Contract intent, not proof.
     */
    public function __construct(
        public string $taskId,
        public string $goal,
        public array $scope,
        public array $nonGoals,
        public array $validation,
        public string $status,
        public int $revision,
        public string $createdAt,
        public string $updatedAt,
        public string $path,
        public string $plannedBy,
        public ?string $baseCommit = null,
        public array $tags = [],
        public array $behaviorAnchors = [],
        public ?string $operatingPromptManifest = null,
        public array $operatingPrompts = [],
        public ?string $approvedBy = null,
        public ?string $approvedAt = null,
        public array $acceptanceCriteria = [],
        public array $acceptanceObservations = [],
    ) {
    }

    /** @return list<string> */
    public function uncoveredAcceptanceCriteria(): array
    {
        $covered = [];
        foreach ($this->acceptanceObservations as $observation) {
            $covered[$observation['acceptance']] = true;
        }

        return array_values(array_filter(
            $this->acceptanceCriteria,
            static fn (string $criterion): bool => !isset($covered[$criterion]),
        ));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'task_contract',
            'task_id' => $this->taskId,
            'goal' => $this->goal,
            'scope' => $this->scope,
            'non_goals' => $this->nonGoals,
            'validation' => $this->validation,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'acceptance_observations' => $this->acceptanceObservations,
            'tags' => $this->tags,
            'behavior_anchors' => $this->behaviorAnchors,
            'operating_prompt_manifest' => $this->operatingPromptManifest,
            'operating_prompts' => $this->operatingPrompts,
            'status' => $this->status,
            'revision' => $this->revision,
            'planned_by' => $this->plannedBy,
            'base_commit' => $this->baseCommit,
            'approved_by' => $this->approvedBy,
            'approved_at' => $this->approvedAt,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
