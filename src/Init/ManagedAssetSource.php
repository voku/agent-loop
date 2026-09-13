<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/** Stable semantic identity plus the current local source location for one projected asset. */
final readonly class ManagedAssetSource
{
    private function __construct(
        public string $id,
        public string $owner,
        public string $path,
        public ?string $reference,
    ) {
    }

    public static function fromPath(string $projectRoot, string $sourcePath, string $assetId): self
    {
        $projectRoot = self::normalize($projectRoot);
        $sourcePath = self::normalize($sourcePath);
        $owner = FirstPartyPackageCatalog::ownerForPath($sourcePath, $projectRoot);
        $ownerRoot = FirstPartyPackageCatalog::packageRootForOwner($owner);

        return new self(
            $owner . ':' . ltrim($assetId, ':'),
            $owner,
            $sourcePath,
            $ownerRoot === null ? null : self::relativeTo($sourcePath, $ownerRoot),
        );
    }

    public static function fromFirstPartyExport(string $owner, string $sourcePath, string $assetId): self
    {
        if (!FirstPartyPackageCatalog::isFirstPartyOwner($owner)) {
            throw new InvalidArgumentException('Managed first-party asset owner is not trusted: ' . $owner);
        }

        $sourcePath = self::normalize($sourcePath);
        $ownerRoot = FirstPartyPackageCatalog::packageRootForOwner($owner);
        if ($ownerRoot === null) {
            throw new InvalidArgumentException('Managed first-party asset owner is not installed: ' . $owner);
        }
        $ownerRoot = self::normalize($ownerRoot);
        if (!self::inside($sourcePath, $ownerRoot)) {
            throw new InvalidArgumentException('Managed first-party asset source is outside its owner root: ' . $owner);
        }

        return new self(
            $owner . ':' . ltrim($assetId, ':'),
            $owner,
            $sourcePath,
            self::relativeTo($sourcePath, $ownerRoot),
        );
    }

    /**
     * Resolves persisted provenance against the currently installed semantic owner.
     *
     * A portable reference always wins. A null reference deliberately means the
     * manifest is using the older/local path-bound policy and is not reinterpreted.
     */
    public static function resolvePersistedPath(string $owner, ?string $reference, ?string $sourcePath): ?string
    {
        return FirstPartyPackageCatalog::resolvePersistedPath($owner, $reference, $sourcePath);
    }

    private static function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, rtrim($root, '/') . '/');
    }

    private static function normalize(string $path): string
    {
        $real = realpath($path);
        $path = $real === false ? $path : $real;

        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function relativeTo(string $path, string $root): string
    {
        $reference = ltrim(substr($path, strlen(rtrim($root, '/'))), '/');

        return $reference === '' ? '.' : $reference;
    }
}
