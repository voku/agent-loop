<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * One intended change to one projected entry.
 *
 * `reason` is required for BLOCKED and explains, in owner terms, why the
 * entry will not be touched.
 */
final readonly class ManagedAssetOperation
{
    /** @param non-empty-string $host */
    public function __construct(
        public ManagedAssetOperationKind $operation,
        public string $host,
        public ManagedAssetKind $kind,
        public string $entry,
        public string $targetPath,
        public ?string $reason = null,
    ) {
    }

    /**
     * @return array{
     *     operation: string,
     *     host: non-empty-string,
     *     kind: string,
     *     entry: string,
     *     target_path: string,
     *     reason: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation->value,
            'host' => $this->host,
            'kind' => $this->kind->value,
            'entry' => $this->entry,
            'target_path' => $this->targetPath,
            'reason' => $this->reason,
        ];
    }
}
