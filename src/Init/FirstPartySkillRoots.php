<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use RuntimeException;
use voku\AgentLoop\PackageResources;

/**
 * The skill sources `init install-assets` projects into a host.
 *
 * agent-loop ships its own skills and re-exports skills from sibling owner
 * packages, so a projection legitimately contains entries that no directory in
 * *this* repository owns. Anything that reasons about the projected set has to
 * know every root: when only the repository root was consulted, the Recall
 * skills were reported as stale managed entries immediately after a successful
 * install.
 *
 * Recall is a required dependency and the supported ^0.15 line owns its shipped
 * asset paths through PackageResources. agent-session remains optional at this
 * projection boundary because older supported Session releases may ship no skills.
 */
final readonly class FirstPartySkillRoots
{
    /**
     * @return list<string>
     * @throws RuntimeException when the installed Recall skill root is missing
     */
    public static function resolve(string $packageRoot, ?string $projectRoot = null): array
    {
        $recallRoot = self::ownerSkillRoot(
            'voku/agent-recall-compiler',
            self::recallSkillRoot(),
            $projectRoot,
        );
        if ($recallRoot === null || !is_dir($recallRoot)) {
            throw new RuntimeException('Unable to resolve the installed agent-recall-compiler skill root.');
        }

        $roots = [
            $packageRoot . '/' . PackageResources::SKILLS,
            $recallRoot,
        ];

        $learningRoot = self::ownerSkillRoot(
            'voku/agent-learning',
            self::learningSkillRoot(),
            $projectRoot,
        );
        if ($learningRoot !== null) {
            $roots[] = $learningRoot;
        }

        $sessionRoot = self::ownerSkillRoot(
            'voku/agent-session',
            self::sessionSkillRoot(),
            $projectRoot,
        );
        if ($sessionRoot !== null) {
            $roots[] = $sessionRoot;
        }

        return $roots;
    }

    /**
     * Skill directory names the sibling owner packages contribute, or an empty
     * list when none is installed. A read-only status report should remain
     * usable.
     *
     * @return list<string>
     */
    public static function siblingSkillEntries(): array
    {
        $entries = [
            ...self::skillEntriesIn(self::recallSkillRoot()),
            ...self::skillEntriesIn(self::learningSkillRoot()),
            ...self::skillEntriesIn(self::sessionSkillRoot()),
        ];

        $entries = array_values(array_unique($entries));
        sort($entries);

        return $entries;
    }

    /**
     * @return list<string>
     */
    private static function skillEntriesIn(?string $root): array
    {
        if ($root === null || !is_dir($root)) {
            return [];
        }

        $entries = [];
        foreach ((array) scandir($root) as $entry) {
            if (!is_string($entry) || str_starts_with($entry, '.')) {
                continue;
            }

            if (is_file($root . '/' . $entry . '/SKILL.md')) {
                $entries[] = $entry;
            }
        }

        sort($entries);

        return $entries;
    }

    private static function recallSkillRoot(): string
    {
        return \voku\AgentRecallCompiler\PackageResources::skillsRoot();
    }

    private static function learningSkillRoot(): ?string
    {
        if (!class_exists(\voku\AgentLearning\PackageResources::class)) {
            return null;
        }

        $root = \voku\AgentLearning\PackageResources::skillsRoot();

        return is_dir($root) ? $root : null;
    }

    /**
     * Unlike Recall, a missing root here is a supported state rather than an
     * error: `RepositorySetupService::expectedSkillEntries()` treats every root
     * `resolve()` returns as mandatory, so an agent-session release that ships
     * no skills must be omitted instead of contributed as a dangling path.
     */
    private static function sessionSkillRoot(): ?string
    {
        if (!class_exists(\voku\AgentSession\PackageResources::class)) {
            return null;
        }

        $root = \voku\AgentSession\PackageResources::skillsRoot();

        return is_dir($root) ? $root : null;
    }

    /**
     * An owner checkout's exported resources are current source truth. Falling
     * back to an installed copy would mix package versions and duplicate its
     * skill ids when the project also exposes its local resource tree.
     */
    private static function ownerSkillRoot(string $owner, ?string $installedRoot, ?string $projectRoot): ?string
    {
        if ($installedRoot === null || $projectRoot === null || !FirstPartyPackageCatalog::isOwnerRepository($projectRoot, $owner)) {
            return $installedRoot;
        }

        $installedPackageRoot = FirstPartyPackageCatalog::packageRoot($owner);
        $ownerRoot = FirstPartyPackageCatalog::sourceRootForProject($owner, $projectRoot);
        if ($installedPackageRoot === null || $ownerRoot === null) {
            return null;
        }

        $prefix = rtrim($installedPackageRoot, '/') . '/';
        if (!str_starts_with($installedRoot, $prefix)) {
            return null;
        }

        $localRoot = rtrim($ownerRoot, '/') . '/' . substr($installedRoot, strlen($prefix));

        return is_dir($localRoot) ? $localRoot : null;
    }
}
