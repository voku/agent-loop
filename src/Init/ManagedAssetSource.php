<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use ReflectionClass;
use voku\AgentRecallCompiler\Cli as RecallCli;

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
        $packageRoot = self::normalize(dirname(__DIR__, 2));
        $recallRoot = self::recallPackageRoot();

        $owner = match (true) {
            self::inside($sourcePath, $packageRoot) => 'voku/agent-loop',
            $recallRoot !== null && self::inside($sourcePath, $recallRoot) => 'voku/agent-recall-compiler',
            self::inside($sourcePath, $projectRoot) => 'project',
            default => 'local',
        };
        $ownerRoot = match ($owner) {
            'voku/agent-loop' => $packageRoot,
            'voku/agent-recall-compiler' => $recallRoot,
            default => null,
        };

        return new self(
            $owner . ':' . ltrim($assetId, ':'),
            $owner,
            $sourcePath,
            $ownerRoot === null ? null : self::relativeTo($sourcePath, $ownerRoot),
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
        if ($reference === null) {
            return $sourcePath === null ? null : self::normalize($sourcePath);
        }
        if (!self::validReference($reference)) {
            return null;
        }

        $root = match ($owner) {
            'voku/agent-loop' => self::normalize(dirname(__DIR__, 2)),
            'voku/agent-recall-compiler' => self::recallPackageRoot(),
            default => null,
        };
        if ($root === null) {
            return null;
        }

        $candidate = self::normalize($root . '/' . $reference);

        return self::inside($candidate, $root) ? $candidate : null;
    }

    private static function recallPackageRoot(): ?string
    {
        if (!class_exists(RecallCli::class)) {
            return null;
        }

        $file = (new ReflectionClass(RecallCli::class))->getFileName();

        return is_string($file) ? self::normalize(dirname($file, 2)) : null;
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

    private static function validReference(string $reference): bool
    {
        if ($reference === '.') {
            return true;
        }
        if ($reference === '' || str_starts_with($reference, '/') || str_contains($reference, '\\')) {
            return false;
        }

        foreach (explode('/', $reference) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }
}
