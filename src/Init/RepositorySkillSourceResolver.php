<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/** Resolves the repository's desired skill projection before planning or mutation. */
final readonly class RepositorySkillSourceResolver
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array<string, ManagedAssetSource> skill id => semantic source
     */
    public function resolve(AgentAssetSourcePaths $paths, bool $includePackageSkills = true): array
    {
        $sources = [];
        $configuredRoot = $paths->absoluteSkillsRoot();
        if (is_dir($configuredRoot)) {
            foreach (scandir($configuredRoot) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $sourcePath = $configuredRoot . '/' . $entry;
                if (!is_file($sourcePath . '/SKILL.md')) {
                    continue;
                }
                if (!FirstPartyPackageCatalog::isSkillAllowedForProject($entry, $sourcePath, $this->rootPath)) {
                    continue;
                }

                $this->add($sources, $entry, $sourcePath);
            }
        }

        if ($includePackageSkills) {
            foreach (FirstPartyPackageCatalog::exportableSkills($this->rootPath) as $entry => $metadata) {
                $this->add($sources, $entry, $metadata['path']);
            }
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @param array<string, ManagedAssetSource> $sources
     */
    private function add(array &$sources, string $entry, string $sourcePath): void
    {
        $candidate = ManagedAssetSource::fromPath($this->rootPath, $sourcePath, 'skill:' . $entry);
        $existing = $sources[$entry] ?? null;
        if ($existing instanceof ManagedAssetSource && $existing->path !== $candidate->path) {
            throw new InvalidArgumentException('Multiple skill sources own the same entry: ' . $entry);
        }

        $sources[$entry] = $candidate;
    }
}
