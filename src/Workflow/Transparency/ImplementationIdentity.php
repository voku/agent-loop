<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use RuntimeException;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\ImplementationSnapshotUnavailable;
use voku\AgentLoop\Workflow\TaskContract;

/**
 * Current content identity for approved implementation scope.
 *
 * "Not capturable yet" and "refused" stay distinct from "captured an empty
 * set": review currency binds to this digest, so a host that cannot tell them
 * apart cannot explain why a review reads as stale.
 */
final readonly class ImplementationIdentity
{
    /** @param list<array{path: string, sha256: string}> $files */
    private function __construct(
        public ImplementationIdentityStatus $status,
        public ?string $digest,
        public ?int $contractRevision,
        public array $files,
        public ?string $reason,
    ) {
    }

    public static function capture(string $rootPath, ?TaskContract $contract): self
    {
        if ($contract === null) {
            return new self(
                ImplementationIdentityStatus::NO_CONTRACT,
                null,
                null,
                [],
                'No durable Contract, so there is no approved implementation scope to hash.',
            );
        }

        try {
            $snapshot = ImplementationSnapshot::capture($rootPath, $contract);
        } catch (ImplementationSnapshotUnavailable $exception) {
            return new self(
                ImplementationIdentityStatus::UNAVAILABLE,
                null,
                $contract->revision,
                [],
                $exception->getMessage(),
            );
        } catch (RuntimeException $exception) {
            return new self(
                ImplementationIdentityStatus::REFUSED,
                null,
                $contract->revision,
                [],
                $exception->getMessage(),
            );
        }

        return new self(
            ImplementationIdentityStatus::CAPTURED,
            $snapshot->digest,
            $snapshot->contractRevision,
            $snapshot->files,
            null,
        );
    }

    /**
     * @return array{
     *     status: string,
     *     digest: string|null,
     *     contract_revision: int|null,
     *     file_count: int,
     *     files: list<array{path: string, sha256: string}>,
     *     reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'digest' => $this->digest,
            'contract_revision' => $this->contractRevision,
            'file_count' => count($this->files),
            'files' => $this->files,
            'reason' => $this->reason,
        ];
    }
}
