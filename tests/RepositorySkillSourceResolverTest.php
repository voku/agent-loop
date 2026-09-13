<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\RepositorySetupService;
use voku\AgentLoop\Init\RepositorySkillSourceResolver;

final class RepositorySkillSourceResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-skill-source-' . bin2hex(random_bytes(6));
        $this->writeSkill('project-skill', "# Project skill\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testResolveCarriesProjectAndFirstPartyProvenanceInOneSourceMap(): void
    {
        $sources = (new RepositorySkillSourceResolver($this->root))->resolve($this->paths());

        self::assertArrayHasKey('project-skill', $sources);
        self::assertSame('project', $sources['project-skill']->owner);
        self::assertNull($sources['project-skill']->reference);

        self::assertArrayHasKey('agent-recall-consumer', $sources);
        self::assertSame('voku/agent-recall-compiler', $sources['agent-recall-consumer']->owner);
        $recallReference = $sources['agent-recall-consumer']->reference;
        self::assertNotNull($recallReference);
        self::assertFalse(str_starts_with($recallReference, '/'));

        $sorted = array_keys($sources);
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, array_keys($sources));
    }

    public function testResolveCanExcludeFirstPartyPackageSkillsWithoutChangingProjectSource(): void
    {
        $sources = (new RepositorySkillSourceResolver($this->root))->resolve($this->paths(), false);

        self::assertSame(['project-skill'], array_keys($sources));
        self::assertSame('project', $sources['project-skill']->owner);
    }

    public function testDuplicateProjectAndPackageSkillFailsDuringPlanningBeforeAnyWrite(): void
    {
        $this->writeSkill('agent-recall-consumer', "# Conflicting local copy\n");

        try {
            (new RepositorySetupService($this->root))->planInstall('codex', false, $this->paths());
            self::fail('Expected duplicate skill ownership to fail before planning completes.');
        } catch (InvalidArgumentException $exception) {
            self::assertSame(
                'Multiple skill sources own the same entry: agent-recall-consumer',
                $exception->getMessage(),
            );
            self::assertDirectoryDoesNotExist($this->root . '/.codex/skills');
            self::assertFileDoesNotExist($this->root . '/AGENTS.md');
        }
    }

    private function paths(): AgentAssetSourcePaths
    {
        return new AgentAssetSourcePaths(
            $this->root,
            'fixture/skills',
            'fixture/subagents',
            'fixture/hooks',
            'fixture/tools',
            'fixture/claude-hooks',
        );
    }

    private function writeSkill(string $id, string $content): void
    {
        $directory = $this->root . '/fixture/skills/' . $id;
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create skill-source fixture: ' . $directory);
        }
        file_put_contents($directory . '/SKILL.md', $content);
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
