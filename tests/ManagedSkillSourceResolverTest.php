<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\ManagedAssetTargetCatalog;
use voku\AgentLoop\Init\ManagedSkillSourceResolver;

/** @internal */
final class ManagedSkillSourceResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-skill-source-resolver-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'acme/resolver-consumer'], JSON_PRETTY_PRINT),
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testConsumerResolutionCarriesScopedFirstPartyProvenance(): void
    {
        $paths = AgentAssetSourcePaths::fromSources($this->root);
        $sources = (new ManagedSkillSourceResolver($this->root))->resolve($paths);

        self::assertArrayHasKey('agent-learning-consumer', $sources);
        self::assertSame('voku/agent-learning', $sources['agent-learning-consumer']->owner);
        self::assertNotNull($sources['agent-learning-consumer']->reference);

        self::assertArrayHasKey('agent-recall-consumer', $sources);
        self::assertSame('voku/agent-recall-compiler', $sources['agent-recall-consumer']->owner);
        self::assertNotNull($sources['agent-recall-consumer']->reference);

        self::assertArrayNotHasKey('agent-learning-maintainer', $sources);
        self::assertArrayNotHasKey('agent-recall-compiler-maintainer', $sources);
        self::assertArrayNotHasKey('agent-session-maintainer', $sources);
    }

    public function testTargetCatalogUsesTheResolvedSkillSet(): void
    {
        $paths = AgentAssetSourcePaths::fromSources($this->root);
        $expected = array_keys((new ManagedSkillSourceResolver($this->root))->resolve($paths));

        self::assertSame(
            $expected,
            (new ManagedAssetTargetCatalog($this->root))->skillEntries($paths),
        );
    }

    public function testConfiguredDuplicateOfFirstPartySkillFailsBeforeMutation(): void
    {
        $customRoot = $this->root . '/custom-skills';
        $duplicate = $customRoot . '/agent-learning-consumer';
        mkdir($duplicate, 0o775, true);
        file_put_contents($duplicate . '/SKILL.md', "# conflicting local skill\n");

        $paths = AgentAssetSourcePaths::fromSources(
            $this->root,
            ['skills_root' => 'custom-skills'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Multiple skill sources own the same entry: agent-learning-consumer');

        (new ManagedSkillSourceResolver($this->root))->resolve($paths);
    }

    public function testConfiguredOnlyResolutionPreservesProjectOwnership(): void
    {
        $customRoot = $this->root . '/custom-skills';
        $skillRoot = $customRoot . '/acme-project-skill';
        mkdir($skillRoot, 0o775, true);
        file_put_contents($skillRoot . '/SKILL.md', "# project skill\n");

        $paths = AgentAssetSourcePaths::fromSources(
            $this->root,
            ['skills_root' => 'custom-skills'],
        );
        $sources = (new ManagedSkillSourceResolver($this->root))->resolve($paths, false);

        self::assertSame(['acme-project-skill'], array_keys($sources));
        self::assertSame('project', $sources['acme-project-skill']->owner);
        self::assertNull($sources['acme-project-skill']->reference);
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
