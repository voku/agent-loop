<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use voku\AgentLoop\Run\CanonicalJson;

/**
 * An explicit, exact-Contract task-autonomy *request*, not proof of the
 * grantor's identity. Only an authenticated owner may promote this to
 * execution authority. In particular, --by is attribution, not identity.
 */
final readonly class TaskAutonomyGrant
{
    /** @var non-empty-list<'implement'|'validate'|'review'|'repair'> */
    public const array OPERATIONS = ['implement', 'validate', 'review', 'repair'];

    public function __construct(
        public string $taskId,
        public int $contractRevision,
        public string $contractDigest,
        public string $requestedBy,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $revokedAt = null,
    ) {
        new WorkflowTaskId($taskId);
        if ($contractRevision < 1 || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $contractDigest) !== 1) {
            throw new InvalidArgumentException('Task autonomy requires an exact Contract revision and SHA-256 digest.');
        }

        if (trim($requestedBy) === '' || str_starts_with(trim($requestedBy), 'standing:')) {
            throw new InvalidArgumentException('Task autonomy requires a named, non-reserved requester.');
        }

        if ($expiresAt <= $issuedAt || $expiresAt > $issuedAt->modify('+24 hours')) {
            throw new InvalidArgumentException('Task autonomy expiry must be after issuance and at most 24 hours later.');
        }

        if ($revokedAt !== null && $revokedAt < $issuedAt) {
            throw new InvalidArgumentException('Task autonomy revocation cannot predate issuance.');
        }
    }

    public static function request(TaskContract $contract, string $by, DateTimeImmutable $now, DateTimeImmutable $until): self
    {
        if (!$contract->hasNonReservedApproval()) {
            throw new RuntimeException('Task autonomy requires a currently human-approved Contract.');
        }

        return new self(
            $contract->taskId,
            $contract->revision,
            self::contractDigest($contract),
            $by,
            $now,
            $until,
        );
    }

    public function revoke(DateTimeImmutable $now): self
    {
        if ($now < $this->issuedAt) {
            throw new InvalidArgumentException('Task autonomy cannot be revoked before issuance.');
        }

        return new self(
            $this->taskId,
            $this->contractRevision,
            $this->contractDigest,
            $this->requestedBy,
            $this->issuedAt,
            $this->expiresAt,
            $this->revokedAt ?? $now,
        );
    }

    public function permits(TaskContract $current, string $operation, DateTimeImmutable $now): bool
    {
        return in_array($operation, self::OPERATIONS, true)
            && $this->revokedAt === null
            && $now >= $this->issuedAt
            && $now < $this->expiresAt
            && $current->hasNonReservedApproval()
            && $current->taskId === $this->taskId
            && $current->revision === $this->contractRevision
            && hash_equals($this->contractDigest, self::contractDigest($current));
    }

    public static function contractDigest(TaskContract $contract): string
    {
        return 'sha256:' . hash('sha256', CanonicalJson::pretty($contract->toArray()));
    }
}
