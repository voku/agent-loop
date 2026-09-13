<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\PackageResources;

/** Resolves the desired managed subagent set once, with provenance attached. */
final readonly class ManagedSubagentSourceResolver
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array<string, ManagedSubagentSource> subagent-name => source
     */
    public function resolve(AgentAssetSourcePaths $paths, ?bool $includePackageSubagents = null): array
    {
        $includePackageSubagents ??= $paths->packageSubagents();
        $sources = [];

        if ($includePackageSubagents) {
            $packageRoot = PackageResources::subagentsRoot();
            if (is_dir($packageRoot)) {
                $this->scanDirectory($sources, $packageRoot, true);
            }
        }

        $configuredRoot = $paths->absoluteSubagentsRoot();
        if (is_dir($configuredRoot)) {
            $packageRoot = PackageResources::subagentsRoot();
            $isPackageRoot = realpath($configuredRoot) === realpath($packageRoot);

            if (!$isPackageRoot || $includePackageSubagents) {
                $this->scanDirectory($sources, $configuredRoot, false);
            }
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @param array<string, ManagedSubagentSource> $sources
     */
    private function scanDirectory(array &$sources, string $root, bool $isFirstParty): void
    {
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }

            $sourcePath = $root . '/' . $entry;
            if (!is_file($sourcePath)) {
                continue;
            }

            $name = substr($entry, 0, -3);
            $assetSource = $isFirstParty
                ? ManagedAssetSource::fromFirstPartyExport(
                    'voku/agent-loop',
                    $sourcePath,
                    'subagent:' . $name,
                )
                : ManagedAssetSource::fromPath(
                    $this->rootPath,
                    $sourcePath,
                    'subagent:' . $name,
                );

            $this->register(
                $sources,
                $name,
                new ManagedSubagentSource(
                    $name,
                    $sourcePath,
                    $assetSource,
                ),
            );
        }
    }

    /**
     * @param array<string, ManagedSubagentSource> $sources
     */
    private function register(array &$sources, string $name, ManagedSubagentSource $source): void
    {
        $existing = $sources[$name] ?? null;
        if ($existing instanceof ManagedSubagentSource && realpath($existing->path) !== realpath($source->path)) {
            throw new InvalidArgumentException('Multiple subagent sources own the same entry: ' . $name);
        }

        $sources[$name] = $source;
    }
}
