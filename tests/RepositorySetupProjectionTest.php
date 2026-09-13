<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\RepositorySetupContributor;
use voku\AgentLoop\Init\RepositorySetupContributorRole;
use voku\AgentLoop\Init\RepositorySetupService;

final class RepositorySetupProjectionTest extends TestCase
{
    private string $root;

    private string $binRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-projection-' . bin2hex(random_bytes(6));
        $this->binRoot = $this->root . '/bin';
        if (!mkdir($this->binRoot, 0o775, true) && !is_dir($this->binRoot)) {
            throw new RuntimeException('Unable to create fixture bin root.');
        }
        $this->createExecutable('opencode');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testContributorAttributionInConsumerRepository(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'example/consumer-application'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        $projection = (new RepositorySetupService($this->root, $probe))->overview();

        self::assertNotEmpty($projection->contributors);

        $byOwner = [];
        foreach ($projection->contributors as $contributor) {
            $byOwner[$contributor->owner] = $contributor;
        }

        self::assertArrayHasKey('voku/agent-loop', $byOwner);
        self::assertSame(RepositorySetupContributorRole::CONSUMER, $byOwner['voku/agent-loop']->role);
        self::assertGreaterThanOrEqual(1, $byOwner['voku/agent-loop']->skills);
        self::assertGreaterThanOrEqual(1, $byOwner['voku/agent-loop']->subagents);

        self::assertArrayHasKey('voku/agent-learning', $byOwner);
        self::assertSame(RepositorySetupContributorRole::CONSUMER, $byOwner['voku/agent-learning']->role);
        self::assertSame(4, $byOwner['voku/agent-learning']->skills);
        self::assertSame(0, $byOwner['voku/agent-learning']->subagents);

        self::assertArrayHasKey('voku/agent-recall-compiler', $byOwner);
        self::assertSame(RepositorySetupContributorRole::CONSUMER, $byOwner['voku/agent-recall-compiler']->role);
        self::assertSame(1, $byOwner['voku/agent-recall-compiler']->skills);
        self::assertSame(0, $byOwner['voku/agent-recall-compiler']->subagents);

        $array = $projection->toArray();
        self::assertSame(
            array_map(static fn (RepositorySetupContributor $c): array => $c->toArray(), $projection->contributors),
            $array['contributors'],
        );
    }

    public function testContributorAttributionInOwnerRepository(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'voku/agent-learning'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );

        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        $projection = (new RepositorySetupService($this->root, $probe))->overview();

        $byOwner = [];
        foreach ($projection->contributors as $contributor) {
            $byOwner[$contributor->owner] = $contributor;
        }

        self::assertArrayHasKey('voku/agent-learning', $byOwner);
        self::assertSame(RepositorySetupContributorRole::MAINTAINER, $byOwner['voku/agent-learning']->role);
        self::assertSame(5, $byOwner['voku/agent-learning']->skills);

        self::assertArrayHasKey('voku/agent-loop', $byOwner);
        self::assertSame(RepositorySetupContributorRole::CONSUMER, $byOwner['voku/agent-loop']->role);
    }

    public function testContributorAttributionWithProjectLocalSource(): void
    {
        $skillRoot = $this->root . '/custom-skills/project-specific-skill';
        if (!mkdir($skillRoot, 0o775, true) && !is_dir($skillRoot)) {
            throw new RuntimeException('Unable to create project skill fixture.');
        }
        file_put_contents($skillRoot . '/SKILL.md', "# Project specific skill\n");

        $configRoot = $this->root . '/.agent-loop';
        if (!mkdir($configRoot, 0o775, true) && !is_dir($configRoot)) {
            throw new RuntimeException('Unable to create init config fixture.');
        }
        file_put_contents(
            $configRoot . '/init.json',
            json_encode([
                'paths' => [
                    'skills_root' => 'custom-skills',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        );

        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        $projection = (new RepositorySetupService($this->root, $probe))->overview();

        $byOwner = [];
        foreach ($projection->contributors as $contributor) {
            $byOwner[$contributor->owner] = $contributor;
        }

        self::assertArrayHasKey('project', $byOwner);
        self::assertSame(RepositorySetupContributorRole::PROJECT, $byOwner['project']->role);
        self::assertSame(1, $byOwner['project']->skills);
        self::assertSame(0, $byOwner['project']->subagents);

        self::assertArrayHasKey('voku/agent-loop', $byOwner);
        self::assertArrayHasKey('voku/agent-learning', $byOwner);
    }

    public function testContributorAttributionWithDisabledPackageAssets(): void
    {
        $skillRoot = $this->root . '/custom-skills/project-specific-skill';
        if (!mkdir($skillRoot, 0o775, true) && !is_dir($skillRoot)) {
            throw new RuntimeException('Unable to create project skill fixture.');
        }
        file_put_contents($skillRoot . '/SKILL.md', "# Project specific skill\n");

        $subagentRoot = $this->root . '/custom-subagents';
        if (!mkdir($subagentRoot, 0o775, true) && !is_dir($subagentRoot)) {
            throw new RuntimeException('Unable to create project subagent fixture.');
        }
        file_put_contents(
            $subagentRoot . '/project-subagent.md',
            "---\nname: project-subagent\ndescription: Project subagent.\n---\n\nSubagent body.\n",
        );

        $configRoot = $this->root . '/.agent-loop';
        if (!mkdir($configRoot, 0o775, true) && !is_dir($configRoot)) {
            throw new RuntimeException('Unable to create init config fixture.');
        }
        file_put_contents(
            $configRoot . '/init.json',
            json_encode([
                'package_skills' => false,
                'package_subagents' => false,
                'paths' => [
                    'skills_root' => 'custom-skills',
                    'subagents_root' => 'custom-subagents',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        );

        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        $projection = (new RepositorySetupService($this->root, $probe))->overview();

        self::assertCount(1, $projection->contributors);
        $contributor = $projection->contributors[0];
        self::assertSame('project', $contributor->owner);
        self::assertSame(RepositorySetupContributorRole::PROJECT, $contributor->role);
        self::assertSame(1, $contributor->skills);
        self::assertSame(1, $contributor->subagents);
    }

    public function testContributorAttributionWithDisabledPackageAssetsAndNoProjectSources(): void
    {
        $configRoot = $this->root . '/.agent-loop';
        if (!mkdir($configRoot, 0o775, true) && !is_dir($configRoot)) {
            throw new RuntimeException('Unable to create init config fixture.');
        }
        file_put_contents(
            $configRoot . '/init.json',
            json_encode([
                'package_skills' => false,
                'package_subagents' => false,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        );

        // When no host is probed/selected, overview() does not call manifestReady() or require expected skills
        $probe = new HostRuntimeProbe($this->root . '/empty-bin', self::pathExt());
        $projection = (new RepositorySetupService($this->root, $probe))->overview();

        self::assertSame([], $projection->contributors);
    }

    private function createExecutable(string $name): void
    {
        $path = $this->binRoot . DIRECTORY_SEPARATOR . self::executableFileName($name);
        if (file_put_contents($path, "#!/bin/sh\nexit 0\n") === false) {
            throw new RuntimeException('Unable to create fake host executable: ' . $path);
        }
        if (DIRECTORY_SEPARATOR === '/' && !chmod($path, 0o755)) {
            throw new RuntimeException('Unable to make fake host executable: ' . $path);
        }
    }

    private static function executableFileName(string $name): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? $name . '.cmd' : $name;
    }

    private static function pathExt(): ?string
    {
        return DIRECTORY_SEPARATOR === '\\' ? '.EXE' : null;
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
