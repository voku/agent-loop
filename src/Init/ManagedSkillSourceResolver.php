<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

/** Resolves the desired managed skill set once, with provenance attached. */
final readonly class ManagedSkillSourceResolver
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array<string, ManagedAssetSource> skill-id => source
     */
    public function resolve(AgentAssetSourcePaths $paths, bool $includeFirstParty = true): array
    {
        $sources = [];

        if ($includeFirstParty) {
            foreach (FirstPartyPackageCatalog::exportableSkills($this->rootPath) as $entry => $metadata) {
                $this->register(
                    $sources,
                    $entry,
                    ManagedAssetSource::fromFirstPartyExport(
                        $metadata['owner'],
                        $metadata['path'],
                        'skill:' . $entry,
                    ),
                );
            }
        }

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

                $this->register(
                    $sources,
                    $entry,
                    ManagedAssetSource::fromPath($this->rootPath, $sourcePath, 'skill:' . $entry),
                );
            }
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @param array<string, ManagedAssetSource> $sources
     */
    private function register(array &$sources, string $entry, ManagedAssetSource $source): void
    {
        $existing = $sources[$entry] ?? null;
        if ($existing instanceof ManagedAssetSource && $existing->path !== $source->path) {
            throw new InvalidArgumentException('Multiple skill sources own the same entry: ' . $entry);
        }

        $sources[$entry] = $source;
    }
}
