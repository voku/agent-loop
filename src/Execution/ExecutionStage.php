<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class ExecutionStage
{
    /**
     * @param list<non-empty-string> $requires
     * @param array<string, non-empty-string|null> $transitions outcome value => next stage id, null when terminal
     */
    public function __construct(
        public string $id,
        public ExecutionStageKind $kind,
        public ?string $roleId,
        public bool $mayMutate,
        public array $requires,
        public array $transitions,
    ) {
        if (preg_match('/^[a-z][a-z0-9-]*$/', $this->id) !== 1) {
            throw new InvalidArgumentException('Execution stage id must match [a-z][a-z0-9-]*.');
        }
        if ($this->kind === ExecutionStageKind::AGENT && ($this->roleId === null || trim($this->roleId) === '')) {
            throw new InvalidArgumentException('Agent execution stages require a role id.');
        }
        if ($this->kind === ExecutionStageKind::DETERMINISTIC && $this->roleId !== null) {
            throw new InvalidArgumentException('Deterministic execution stages must not declare an agent role.');
        }
        foreach ($this->requires as $requiredStage) {
            if (preg_match('/^[a-z][a-z0-9-]*$/', $requiredStage) !== 1) {
                throw new InvalidArgumentException('Execution stage dependencies must be valid stage ids.');
            }
        }
        foreach ($this->transitions as $outcome => $nextStage) {
            if (StageOutcome::tryFrom($outcome) === null) {
                throw new InvalidArgumentException('Unsupported execution stage outcome: ' . $outcome);
            }
            if ($nextStage !== null && preg_match('/^[a-z][a-z0-9-]*$/', $nextStage) !== 1) {
                throw new InvalidArgumentException('Execution stage transition target must be a valid stage id or null.');
            }
        }
    }

    /**
     * @return array{
     *     id: string,
     *     kind: string,
     *     role: string|null,
     *     may_mutate: bool,
     *     requires: list<non-empty-string>,
     *     transitions: array<string, non-empty-string|null>
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind->value,
            'role' => $this->roleId,
            'may_mutate' => $this->mayMutate,
            'requires' => $this->requires,
            'transitions' => $this->transitions,
        ];
    }

    public function next(StageOutcome $outcome): ?string
    {
        if (!array_key_exists($outcome->value, $this->transitions)) {
            throw new InvalidArgumentException(sprintf(
                'Outcome %s is not legal for execution stage %s.',
                $outcome->value,
                $this->id,
            ));
        }

        return $this->transitions[$outcome->value];
    }
}
