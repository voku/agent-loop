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

        return new self(
            $owner . ':' . ltrim($assetId, ':'),
            $owner,
            $sourcePath,
        );
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
}
