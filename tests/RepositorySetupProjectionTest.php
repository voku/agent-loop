<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\RepositorySetupContributor;
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

    public function testOverviewProjectsContributorsInConsumerRepository(): void
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
        self::assertSame(RepositorySetupContributor::SCOPE_CONSUMER, $byOwner['voku/agent-loop']->scope);
        self::assertGreaterThanOrEqual(1, $byOwner['voku/agent-loop']->skillCount);
        self::assertGreaterThanOrEqual(1, $byOwner['voku/agent-loop']->subagentCount);
        self::assertSame(1, $byOwner['voku/agent-loop']->instructionCount);

        self::assertArrayHasKey('voku/agent-learning', $byOwner);
        self::assertSame(RepositorySetupContributor::SCOPE_CONSUMER, $byOwner['voku/agent-learning']->scope);
        self::assertSame(5, $byOwner['voku/agent-learning']->skillCount);
        self::assertSame(0, $byOwner['voku/agent-learning']->subagentCount);
        self::assertSame(1, $byOwner['voku/agent-learning']->instructionCount);

        self::assertArrayHasKey('voku/agent-recall-compiler', $byOwner);
        self::assertSame(RepositorySetupContributor::SCOPE_CONSUMER, $byOwner['voku/agent-recall-compiler']->scope);
        self::assertSame(1, $byOwner['voku/agent-recall-compiler']->skillCount);
        self::assertSame(0, $byOwner['voku/agent-recall-compiler']->subagentCount);
        self::assertSame(0, $byOwner['voku/agent-recall-compiler']->instructionCount);

        $array = $projection->toArray();
        self::assertSame(
            array_map(static fn (RepositorySetupContributor $c): array => $c->toArray(), $projection->contributors),
            $array['contributors'],
        );
    }

    public function testOverviewProjectsContributorsInOwnerRepository(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'voku/agent-learning'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
        foreach (['agent-learning-consumer', 'agent-learning-maintainer', 'agent-learning-note', 'agent-learning-ctx-evidence', 'agent-hard-constraint-author'] as $skill) {
            $dir = $this->root . '/resources/skills/' . $skill;
            mkdir($dir, 0o775, true);
            file_put_contents($dir . '/SKILL.md', '# ' . $skill . "\n");
        }

        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        $projection = (new RepositorySetupService($this->root, $probe))->overview();

        $byOwner = [];
        foreach ($projection->contributors as $contributor) {
            $byOwner[$contributor->owner] = $contributor;
        }

        self::assertArrayHasKey('voku/agent-learning', $byOwner);
        self::assertSame(RepositorySetupContributor::SCOPE_OWNER_REPOSITORY, $byOwner['voku/agent-learning']->scope);
        self::assertSame(5, $byOwner['voku/agent-learning']->skillCount);
        self::assertSame(0, $byOwner['voku/agent-learning']->instructionCount);

        self::assertArrayHasKey('voku/agent-loop', $byOwner);
        self::assertSame(RepositorySetupContributor::SCOPE_CONSUMER, $byOwner['voku/agent-loop']->scope);
    }

    public function testOverviewProjectsProjectLocalContributor(): void
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
        self::assertSame(RepositorySetupContributor::SCOPE_PROJECT, $byOwner['project']->scope);
        self::assertSame(1, $byOwner['project']->skillCount);
        self::assertSame(0, $byOwner['project']->subagentCount);
        self::assertSame(0, $byOwner['project']->instructionCount);

        self::assertArrayHasKey('voku/agent-loop', $byOwner);
        self::assertArrayHasKey('voku/agent-learning', $byOwner);
    }

    public function testOverviewProjectsContributorsWithDisabledPackageAssets(): void
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

        $byOwner = [];
        foreach ($projection->contributors as $contributor) {
            $byOwner[$contributor->owner] = $contributor;
        }

        self::assertArrayHasKey('project', $byOwner);
        self::assertSame(RepositorySetupContributor::SCOPE_PROJECT, $byOwner['project']->scope);
        self::assertSame(1, $byOwner['project']->skillCount);
        self::assertSame(1, $byOwner['project']->subagentCount);
        self::assertSame(0, $byOwner['project']->instructionCount);

        self::assertArrayHasKey('voku/agent-loop', $byOwner);
        self::assertSame(0, $byOwner['voku/agent-loop']->skillCount);
        self::assertSame(0, $byOwner['voku/agent-loop']->subagentCount);
        self::assertSame(1, $byOwner['voku/agent-loop']->instructionCount);

        self::assertArrayHasKey('voku/agent-learning', $byOwner);
        self::assertSame(0, $byOwner['voku/agent-learning']->skillCount);
        self::assertSame(0, $byOwner['voku/agent-learning']->subagentCount);
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
