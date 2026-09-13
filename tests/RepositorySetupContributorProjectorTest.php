<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\RepositorySetupContributor;
use voku\AgentLoop\Init\RepositorySetupContributorProjector;

final class RepositorySetupContributorProjectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-contributors-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/project-skills/custom-skill', 0o775, true)
            && !is_dir($this->root . '/project-skills/custom-skill')) {
            throw new RuntimeException('Unable to create contributor skill fixture.');
        }
        if (!mkdir($this->root . '/project-subagents', 0o775, true)
            && !is_dir($this->root . '/project-subagents')) {
            throw new RuntimeException('Unable to create contributor subagent fixture.');
        }

        file_put_contents($this->root . '/project-skills/custom-skill/SKILL.md', "# Custom skill\n");
        file_put_contents($this->root . '/project-subagents/reviewer.md', "# Reviewer\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testProjectionUsesResolvedPackagePolicyAndKeepsSemanticOwners(): void
    {
        $paths = AgentAssetSourcePaths::fromSources(
            $this->root,
            [
                'skills_root' => 'project-skills',
                'subagents_root' => 'project-subagents',
            ],
            [],
            false,
            false,
        );

        $contributors = (new RepositorySetupContributorProjector($this->root))->project($paths);
        $project = self::contributor($contributors, 'project');
        self::assertSame(RepositorySetupContributor::SCOPE_PROJECT, $project->scope);
        self::assertSame(1, $project->skillCount);
        self::assertSame(1, $project->subagentCount);
        self::assertSame(0, $project->instructionCount);

        $loop = self::contributor($contributors, 'voku/agent-loop');
        self::assertSame(RepositorySetupContributor::SCOPE_CONSUMER, $loop->scope);
        self::assertSame(0, $loop->skillCount);
        self::assertSame(0, $loop->subagentCount);
        self::assertSame(1, $loop->instructionCount);
    }

    public function testRepositoryPackageIdentityControlsFirstPartyContributorScope(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'voku/agent-learning'], JSON_THROW_ON_ERROR),
        );

        $paths = AgentAssetSourcePaths::fromSources(
            $this->root,
            [
                'skills_root' => 'project-skills',
                'subagents_root' => 'project-subagents',
            ],
            [],
            false,
            false,
        );

        $contributors = (new RepositorySetupContributorProjector($this->root))->project($paths);
        $learning = self::contributor($contributors, 'voku/agent-learning');

        self::assertSame(RepositorySetupContributor::SCOPE_OWNER_REPOSITORY, $learning->scope);
    }

    /**
     * @param list<RepositorySetupContributor> $contributors
     */
    private static function contributor(array $contributors, string $owner): RepositorySetupContributor
    {
        foreach ($contributors as $contributor) {
            if ($contributor->owner === $owner) {
                return $contributor;
            }
        }

        self::fail('Missing repository setup contributor: ' . $owner);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
