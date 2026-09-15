<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\PackageResources;

/** Resolves the desired managed skill set once, with provenance attached. */
final readonly class ManagedSkillSourceResolver
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $extraRoots absolute roots merged into the desired set, as `install-assets --extra-skills-root` requests
     * @return array<string, ManagedAssetSource> skill-id => source
     */
    public function resolve(AgentAssetSourcePaths $paths, ?bool $includeFirstParty = null, array $extraRoots = []): array
    {
        $includeFirstParty ??= $paths->packageSkills();
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
                        $this->rootPath,
                    ),
                );
            }
        }

        $configuredRoot = $paths->absoluteSkillsRoot();
        if (is_dir($configuredRoot)) {
            $loopPackageRoot = FirstPartyPackageCatalog::packageRoot('voku/agent-loop');
            $packageSkillsDir = $loopPackageRoot !== null ? $loopPackageRoot . '/' . PackageResources::SKILLS : null;
            $isPackageRoot = $packageSkillsDir !== null && realpath($configuredRoot) === realpath($packageSkillsDir);

            if (!$isPackageRoot || $includeFirstParty) {
                $this->registerRoot($sources, $configuredRoot);
            }
        }

        foreach ($extraRoots as $extraRoot) {
            if (is_dir($extraRoot)) {
                $this->registerRoot($sources, $extraRoot);
            }
        }

        ksort($sources, SORT_STRING);

        return $sources;
    }

    /**
     * @param array<string, ManagedAssetSource> $sources
     */
    private function registerRoot(array &$sources, string $root): void
    {
        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $sourcePath = $root . '/' . $entry;
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

    /**
     * @param array<string, ManagedAssetSource> $sources
     */
    private function register(array &$sources, string $entry, ManagedAssetSource $source): void
    {
        $existing = $sources[$entry] ?? null;
        if ($existing instanceof ManagedAssetSource && $existing->path === $source->path) {
            return;
        }
        if ($existing instanceof ManagedAssetSource && $existing->path !== $source->path) {
            throw new InvalidArgumentException('Multiple skill sources own the same entry: ' . $entry);
        }

        $sources[$entry] = $source;
    }
}
