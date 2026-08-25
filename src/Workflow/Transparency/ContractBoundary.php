<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use voku\AgentLoop\Workflow\TaskContract;

/**
 * What the approved Contract permits and explicitly excludes.
 *
 * Workflow authority. Acceptance criteria are the required outcomes, never
 * evidence that those outcomes were reached.
 */
final readonly class ContractBoundary
{
    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $acceptanceCriteria
     * @param list<string> $behaviorAnchors
     */
    private function __construct(
        public bool $exists,
        public ?string $goal,
        public ?string $status,
        public ?int $revision,
        public array $scope,
        public array $nonGoals,
        public array $acceptanceCriteria,
        public array $behaviorAnchors,
        public ?string $baseCommit,
        public ?string $approvedBy,
        public ?string $approvedAt,
    ) {
    }

    public static function missing(): self
    {
        return new self(false, null, null, null, [], [], [], [], null, null, null);
    }

    public static function fromContract(TaskContract $contract): self
    {
        return new self(
            exists: true,
            goal: $contract->goal,
            status: $contract->status,
            revision: $contract->revision,
            scope: $contract->scope,
            nonGoals: $contract->nonGoals,
            acceptanceCriteria: $contract->acceptanceCriteria,
            behaviorAnchors: $contract->behaviorAnchors,
            baseCommit: $contract->baseCommit,
            approvedBy: $contract->approvedBy,
            approvedAt: $contract->approvedAt,
        );
    }

    public function isApproved(): bool
    {
        return $this->status === TaskContract::APPROVED;
    }

    /**
     * @return array{
     *     exists: bool,
     *     goal: string|null,
     *     status: string|null,
     *     revision: int|null,
     *     approved: bool,
     *     scope: list<string>,
     *     non_goals: list<string>,
     *     acceptance_criteria: list<string>,
     *     behavior_anchors: list<string>,
     *     base_commit: string|null,
     *     approved_by: string|null,
     *     approved_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'exists' => $this->exists,
            'goal' => $this->goal,
            'status' => $this->status,
            'revision' => $this->revision,
            'approved' => $this->isApproved(),
            'scope' => $this->scope,
            'non_goals' => $this->nonGoals,
            'acceptance_criteria' => $this->acceptanceCriteria,
            'behavior_anchors' => $this->behaviorAnchors,
            'base_commit' => $this->baseCommit,
            'approved_by' => $this->approvedBy,
            'approved_at' => $this->approvedAt,
        ];
    }
}
