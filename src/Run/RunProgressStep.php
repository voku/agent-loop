<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use InvalidArgumentException;

/**
 * One presentation-oriented step in the Loop-owned governed workflow.
 *
 * The step is derived from current owner facts by RunPolicyEvaluator. It does
 * not own lifecycle state and must not be used to authorize mutations.
 */
final readonly class RunProgressStep
{
    public const string STATUS_DONE = 'done';
    public const string STATUS_CURRENT = 'current';
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_BLOCKED = 'blocked';
    public const string STATUS_NOT_APPLICABLE = 'not_applicable';

    public function __construct(
        public string $id,
        public string $label,
        public string $status,
        public string $owner,
        public ?string $reason = null,
    ) {
        if (!in_array($status, [
            self::STATUS_DONE,
            self::STATUS_CURRENT,
            self::STATUS_PENDING,
            self::STATUS_BLOCKED,
            self::STATUS_NOT_APPLICABLE,
        ], true)) {
            throw new InvalidArgumentException('Unsupported workflow progress status: ' . $status);
        }
    }

    /** @return array{id: string, label: string, status: string, owner: string, reason: ?string} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'owner' => $this->owner,
            'reason' => $this->reason,
        ];
    }
}
