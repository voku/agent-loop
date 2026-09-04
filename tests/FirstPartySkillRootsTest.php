<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\FirstPartySkillRoots;
use voku\AgentLoop\PackageResources;

/** @internal */
final class FirstPartySkillRootsTest extends TestCase
{
    private const string PACKAGE_ROOT = __DIR__ . '/..';

    public function testResolveIncludesThisPackageAndTheRecallSibling(): void
    {
        $roots = FirstPartySkillRoots::resolve(self::PACKAGE_ROOT);

        self::assertContains(self::PACKAGE_ROOT . '/' . PackageResources::SKILLS, $roots);
        self::assertGreaterThanOrEqual(2, count($roots), 'The own root and Recall are always part of the set.');
    }

    /**
     * `RepositorySetupService::expectedSkillEntries()` throws on any root it
     * cannot read, so a sibling that ships no skills has to be omitted rather
     * than contributed as a dangling path. This is the regression guard for
     * wiring a new sibling in.
     */
    public function testEveryResolvedRootExists(): void
    {
        foreach (FirstPartySkillRoots::resolve(self::PACKAGE_ROOT) as $root) {
            self::assertDirectoryExists($root);
        }
    }

    public function testResolvedRootsAreUnique(): void
    {
        $roots = FirstPartySkillRoots::resolve(self::PACKAGE_ROOT);

        self::assertSame(array_values(array_unique($roots)), $roots);
    }

    public function testSiblingSkillEntriesAreSortedAndUnique(): void
    {
        $entries = FirstPartySkillRoots::siblingSkillEntries();

        $expected = array_values(array_unique($entries));
        sort($expected);

        self::assertSame($expected, $entries);
    }

    /**
     * Recall has shipped skills for as long as this projection has existed, so
     * an empty contribution means the installed dependency set is broken.
     */
    public function testSiblingSkillEntriesContributeTheRecallConsumerSkill(): void
    {
        self::assertContains('agent-recall-consumer', FirstPartySkillRoots::siblingSkillEntries());
    }

    /**
     * agent-session only ships skills from the release that introduced its own
     * `PackageResources`. Both states are supported: contribute the skill when
     * the installed release has it, contribute nothing when it does not - but
     * never resolve a root that is not there.
     */
    public function testSessionSiblingIsProjectedExactlyWhenTheInstalledReleaseShipsIt(): void
    {
        $entries = FirstPartySkillRoots::siblingSkillEntries();

        if (!class_exists(\voku\AgentSession\PackageResources::class)) {
            self::assertNotContains('agent-session-maintainer', $entries);

            return;
        }

        $sessionRoot = \voku\AgentSession\PackageResources::skillsRoot();
        if (!is_dir($sessionRoot)) {
            self::assertNotContains('agent-session-maintainer', $entries);

            return;
        }

        self::assertContains($sessionRoot, FirstPartySkillRoots::resolve(self::PACKAGE_ROOT));
        self::assertContains('agent-session-maintainer', $entries);
    }
}
