<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

/** One owner-backed presentation step in the governed workflow. */
final readonly class WorkflowProgressStep
{
    public const string STATE_DONE = 'done';
    public const string STATE_CURRENT = 'current';
    public const string STATE_PENDING = 'pending';
    public const string STATE_BLOCKED = 'blocked';
    public const string STATE_NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        public string $id,
        public string $label,
        public string $state,
        public string $owner,
        public ?string $evidenceState = null,
        public ?string $reason = null,
    ) {
        if (!in_array($state, [
            self::STATE_DONE,
            self::STATE_CURRENT,
            self::STATE_PENDING,
            self::STATE_BLOCKED,
            self::STATE_NOT_APPLICABLE,
        ], true)) {
            throw new InvalidArgumentException('Unsupported workflow progress state: ' . $state);
        }
    }

    /**
     * @return array{
     *     id: string,
     *     label: string,
     *     state: string,
     *     owner: string,
     *     evidence_state: string|null,
     *     reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'state' => $this->state,
            'owner' => $this->owner,
            'evidence_state' => $this->evidenceState,
            'reason' => $this->reason,
        ];
    }
}
