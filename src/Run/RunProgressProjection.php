<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

/**
 * Read-only presentation projection for one governed task's current workflow.
 *
 * Canonical routing remains owned by RunPolicyEvaluation. This projection only
 * carries that result together with an ordered presentation of the same current
 * owner facts.
 */
final readonly class RunProgressProjection
{
    /** @param list<RunProgressStep> $steps */
    public function __construct(
        public string $taskId,
        public string $state,
        public string $nextAction,
        public string $nextActionKind,
        public array $steps,
    ) {
    }

    /**
     * @return array{
     *   task_id: string,
     *   state: string,
     *   next_action: string,
     *   next_action_kind: string,
     *   steps: list<array{id: string, label: string, status: string, owner: string, reason: ?string}>
     * }
     */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'state' => $this->state,
            'next_action' => $this->nextAction,
            'next_action_kind' => $this->nextActionKind,
            'steps' => array_map(
                static fn (RunProgressStep $step): array => $step->toArray(),
                $this->steps,
            ),
        ];
    }
}
