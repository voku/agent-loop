<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * One (host, asset kind) projection target on disk.
 *
 * The expectation is what the current package sources say should be there.
 * An unknown expectation is a distinct fact from "nothing should be there" and
 * must never collapse into an empty list.
 */
final readonly class ManagedAssetTarget
{
    /**
     * @param non-empty-string $label
     * @param non-empty-string $host
     */
    public function __construct(
        public string $label,
        public string $host,
        public ManagedAssetKind $kind,
        public string $targetRoot,
        public ManagedAssetEntryExpectation $expectation,
    ) {
    }

    /** @return list<string>|null */
    public function desiredEntries(): ?array
    {
        return $this->expectation->entries;
    }

    public function manifestPath(): string
    {
        return rtrim($this->targetRoot, '/') . '/' . InitSyncManifest::fileName();
    }

    public function hasManifest(): bool
    {
        return is_file($this->manifestPath());
    }
}
