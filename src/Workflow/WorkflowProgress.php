<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

/** Read-only ordered workflow progress plus the canonical routing result. */
final readonly class WorkflowProgress
{
    /** @param non-empty-list<WorkflowProgressStep> $steps */
    public function __construct(
        public string $taskId,
        public string $state,
        public array $steps,
        public ?string $currentStepId,
        public string $nextActionKind,
        public string $nextAction,
    ) {
    }

    /**
     * @return array{
     *     task_id: string,
     *     state: string,
     *     current_step_id: string|null,
     *     next_action_kind: string,
     *     next_action: string,
     *     steps: non-empty-list<array{
     *         id: string,
     *         label: string,
     *         state: string,
     *         owner: string,
     *         evidence_state: string|null,
     *         reason: string|null
     *     }>
     * }
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'state' => $this->state,
            'current_step_id' => $this->currentStepId,
            'next_action_kind' => $this->nextActionKind,
            'next_action' => $this->nextAction,
            'steps' => array_map(
                static fn (WorkflowProgressStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
