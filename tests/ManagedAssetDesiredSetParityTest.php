<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\Init\InitHostStatusCommand;
use voku\AgentLoop\Init\InitInstallAssetsCommand;
use voku\AgentLoop\Init\InitStatusCommand;
use voku\AgentLoop\Init\InitSyncManifest;
use voku\AgentLoop\Init\InitSyncSkillsCommand;
use voku\AgentLoop\Init\InitSyncSubagentsCommand;
use voku\AgentLoop\Init\ManagedAssetDriftProjection;
use voku\AgentLoop\Init\ManagedAssetDriftProjector;
use voku\AgentLoop\Init\ManagedAssetKind;
use voku\AgentLoop\Init\ManagedAssetTargetCatalog;
use voku\AgentLoop\Init\ManagedSkillSourceResolver;
use voku\AgentLoop\Init\RepositorySetupService;

/**
 * One resolved init config yields one owner-computed desired managed asset set.
 *
 * install-assets materializes all of it. Default-mode sync materializes only the
 * configured project root, but never prunes or unrecords an entry that stays
 * desired. Explicit roots stay root-exact. status, doctor and host-status read
 * the same set.
 *
 * @internal
 */
final class ManagedAssetDesiredSetParityTest extends TestCase
{
    private const string CONFIG = '.agent-loop/init.json';

    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-desired-set-parity-' . bin2hex(random_bytes(6));
        foreach (['/custom-skills/repository-skill', '/custom-subagents', '/bin'] as $directory) {
            if (!mkdir($this->root . $directory, 0o775, true) && !is_dir($this->root . $directory)) {
                throw new RuntimeException('Unable to create parity fixture directory: ' . $directory);
            }
        }
        file_put_contents(
            $this->root . '/custom-skills/repository-skill/SKILL.md',
            "---\nname: repository-skill\ndescription: Repository skill.\n---\n\nSkill body.\n",
        );
        file_put_contents(
            $this->root . '/custom-subagents/repository-agent.md',
            "---\nname: repository-agent\ndescription: Repository agent.\n---\n\nAgent body.\n",
        );
        $this->writeConfig([]);

        foreach (['CLAUDE_CONFIG_DIR', 'CLAUDE_SKILLS_DIR', 'CLAUDE_AGENTS_DIR'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }

        $this->removeDirectory($this->root);
    }

    public function testConsumerDesiredSetExcludesMaintainerOnlyPackageSkills(): void
    {
        $desired = array_keys((new ManagedSkillSourceResolver($this->root))->resolve($this->configuredPaths()));

        $this->installAssets();

        self::assertContains('agent-loop-workflow', $desired);
        self::assertFileExists($this->root . '/.claude/skills/agent-loop-workflow/SKILL.md');
        foreach (['agent-guidance-maintenance', 'agent-learning', 'agent-loop-dogfood'] as $maintainerSkill) {
            self::assertNotContains($maintainerSkill, $desired);
            self::assertDirectoryDoesNotExist($this->root . '/.claude/skills/' . $maintainerSkill);
        }
    }

    public function testDefaultModeSyncKeepsPackageCopiesInstalledForTheSameConfig(): void
    {
        $this->installAssets();

        $skills = $this->execute(new InitSyncSkillsCommand($this->root), ['--agent=claude', '--config=' . self::CONFIG]);
        $subagents = $this->execute(new InitSyncSubagentsCommand($this->root), ['--agent=claude', '--config=' . self::CONFIG]);

        self::assertStringNotContainsString('removed stale', $skills . $subagents);
        self::assertFileExists($this->root . '/.claude/skills/agent-loop-workflow/SKILL.md');
        self::assertFileExists($this->root . '/.claude/skills/repository-skill/SKILL.md');
        self::assertFileExists($this->root . '/.claude/agents/agent-loop-investigator.md');
        self::assertFileExists($this->root . '/.claude/agents/repository-agent.md');

        $skillManifest = InitSyncManifest::load($this->root . '/.claude/skills', 'skills', 'claude');
        self::assertTrue($skillManifest->isManaged('agent-loop-workflow'));
        self::assertTrue($skillManifest->isManaged('repository-skill'));
        $subagentManifest = InitSyncManifest::load($this->root . '/.claude/agents', 'subagents', 'claude');
        self::assertTrue($subagentManifest->isManaged('agent-loop-investigator.md'));

        $status = $this->execute(new InitStatusCommand($this->root), ['--config=' . self::CONFIG]);
        self::assertStringContainsString('[OK] claude skills: no stale managed entries', $status);
        self::assertStringNotContainsString('[WARN] claude skills: locally modified', $status);
    }

    public function testDefaultModeSyncDoesNotRestoreAMissingPackageCopyAndDiagnosticsKeepReportingIt(): void
    {
        $this->installAssets();
        $this->removeDirectory($this->root . '/.claude/skills/agent-loop-workflow');

        $sync = $this->execute(new InitSyncSkillsCommand($this->root), ['--agent=claude', '--config=' . self::CONFIG]);

        self::assertStringNotContainsString('removed stale', $sync);
        self::assertDirectoryDoesNotExist($this->root . '/.claude/skills/agent-loop-workflow');
        self::assertTrue(
            InitSyncManifest::load($this->root . '/.claude/skills', 'skills', 'claude')->isManaged('agent-loop-workflow'),
            'A retained entry must keep its manifest record even when its copy is missing.',
        );

        $status = $this->execute(new InitStatusCommand($this->root), ['--config=' . self::CONFIG]);
        self::assertMatchesRegularExpression('/\[WARN\] claude skills: locally modified: [^\n]*agent-loop-workflow/', $status);

        self::assertContains('agent-loop-workflow', $this->claudeSkillDrift()->locallyModified);

        $hostStatus = $this->hostStatus();
        self::assertSame('missing', $hostStatus['integration']['skills'] ?? null);
        self::assertSame('vendor/bin/agent-loop init install-assets --agent=claude', $hostStatus['next_action']);
    }

    public function testDisablingPackageAssetsLetsDefaultModeSyncPruneTheirCopies(): void
    {
        $this->installAssets();
        $this->writeConfig(['package_skills' => false, 'package_subagents' => false]);

        $skills = $this->execute(new InitSyncSkillsCommand($this->root), ['--agent=claude', '--config=' . self::CONFIG]);
        $subagents = $this->execute(new InitSyncSubagentsCommand($this->root), ['--agent=claude', '--config=' . self::CONFIG]);

        self::assertStringContainsString('removed stale', $skills);
        self::assertStringContainsString('removed stale', $subagents);
        self::assertDirectoryDoesNotExist($this->root . '/.claude/skills/agent-loop-workflow');
        self::assertFileDoesNotExist($this->root . '/.claude/agents/agent-loop-investigator.md');
        self::assertSame(
            ['repository-skill'],
            InitSyncManifest::load($this->root . '/.claude/skills', 'skills', 'claude')->managedEntries(),
        );

        $status = $this->execute(new InitStatusCommand($this->root), ['--config=' . self::CONFIG]);
        self::assertStringContainsString('[OK] claude skills: no stale managed entries', $status);
        self::assertStringNotContainsString('agent-loop-workflow', $status);

        $drift = $this->claudeSkillDrift();
        self::assertSame([], $drift->stale);
        self::assertSame(['repository-skill'], $drift->current);
    }

    public function testFalseFlagsStayFalseAcrossEveryDesiredSetConsumer(): void
    {
        $this->writeConfig(['package_skills' => false, 'package_subagents' => false]);
        $paths = $this->configuredPaths();

        self::assertFalse($paths->packageSkills());
        self::assertFalse($paths->packageSubagents());
        self::assertSame(['repository-skill'], (new ManagedAssetTargetCatalog($this->root))->skillEntries($paths));

        $plannedSkills = [];
        foreach ((new RepositorySetupService($this->root))->planInstall('claude')->operations as $operation) {
            if ($operation->kind === ManagedAssetKind::SKILLS) {
                $plannedSkills[] = $operation->entry;
            }
        }
        self::assertSame(['repository-skill'], $plannedSkills);

        $this->installAssets();
        $this->execute(new InitSyncSkillsCommand($this->root), ['--agent=claude', '--config=' . self::CONFIG]);

        self::assertDirectoryDoesNotExist($this->root . '/.claude/skills/agent-loop-workflow');
        self::assertFileDoesNotExist($this->root . '/.claude/agents/agent-loop-investigator.md');
        self::assertSame(
            ['repository-skill'],
            InitSyncManifest::load($this->root . '/.claude/skills', 'skills', 'claude')->managedEntries(),
        );
    }

    public function testExplicitRootsStayRootExactAndGainNoPackageEntries(): void
    {
        $skills = $this->execute(new InitSyncSkillsCommand($this->root), ['--agent=claude', '--skills-root=custom-skills']);
        $subagents = $this->execute(new InitSyncSubagentsCommand($this->root), ['--agent=claude', '--subagents-root=custom-subagents']);

        self::assertStringContainsString('from 1 source root(s)', $skills);
        self::assertStringContainsString('synced 1 subagent file(s)', $subagents);
        self::assertSame(
            ['repository-skill'],
            InitSyncManifest::load($this->root . '/.claude/skills', 'skills', 'claude')->managedEntries(),
        );
        self::assertSame(
            ['repository-agent.md'],
            InitSyncManifest::load($this->root . '/.claude/agents', 'subagents', 'claude')->managedEntries(),
        );
        self::assertDirectoryDoesNotExist($this->root . '/.claude/skills/agent-loop-workflow');
    }

    public function testStatusDoctorHostStatusAndSyncShareOneDesiredSet(): void
    {
        $paths = $this->configuredPaths();
        $desired = array_keys((new ManagedSkillSourceResolver($this->root))->resolve($paths));

        self::assertSame($desired, (new ManagedAssetTargetCatalog($this->root))->skillEntries($paths));

        $this->installAssets();
        $manifestRoot = $this->root . '/.claude/skills';
        self::assertEquals($desired, InitSyncManifest::load($manifestRoot, 'skills', 'claude')->managedEntries());

        $this->execute(new InitSyncSkillsCommand($this->root), ['--agent=claude', '--config=' . self::CONFIG]);
        self::assertSame($desired, InitSyncManifest::load($manifestRoot, 'skills', 'claude')->managedEntries());

        $drift = $this->claudeSkillDrift();
        self::assertSame($desired, $drift->current);
        self::assertSame([], $drift->stale);
        self::assertSame([], $drift->locallyModified);

        self::assertSame('ready', $this->hostStatus()['integration']['skills'] ?? null);
    }

    /** @param array<string, bool> $packageFlags */
    private function writeConfig(array $packageFlags): void
    {
        if (!is_dir($this->root . '/.agent-loop') && !mkdir($this->root . '/.agent-loop', 0o775, true)) {
            throw new RuntimeException('Unable to create config directory.');
        }

        file_put_contents(
            $this->root . '/' . self::CONFIG,
            json_encode([
                ...$packageFlags,
                'paths' => [
                    'skills_root' => 'custom-skills',
                    'subagents_root' => 'custom-subagents',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    private function configuredPaths(): AgentAssetSourcePaths
    {
        return AgentAssetSourcePaths::fromConfig($this->root, (new InitConfigLoader($this->root))->load(self::CONFIG));
    }

    private function installAssets(): void
    {
        $output = $this->execute(new InitInstallAssetsCommand($this->root), ['--agent=claude']);

        self::assertStringContainsString('[OK] install assets:', $output);
    }

    private function claudeSkillDrift(): ManagedAssetDriftProjection
    {
        $projections = (new ManagedAssetDriftProjector())->project(
            new ManagedAssetTargetCatalog($this->root),
            $this->configuredPaths(),
        );
        foreach ($projections as $projection) {
            if ($projection->target->host === 'claude' && $projection->target->kind === ManagedAssetKind::SKILLS) {
                return $projection;
            }
        }

        self::fail('No claude skills drift projection was produced.');
    }

    /** @return array<string, mixed> */
    private function hostStatus(): array
    {
        $output = $this->execute(
            new InitHostStatusCommand($this->root, new HostRuntimeProbe($this->root . '/bin', null)),
            ['--agent=claude', '--format=json'],
        );
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param list<string> $tokens */
    private function execute(InitInstallAssetsCommand|InitSyncSkillsCommand|InitSyncSubagentsCommand|InitStatusCommand|InitHostStatusCommand $command, array $tokens): string
    {
        ob_start();
        try {
            $exit = $command->run($tokens);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, $output);

        return $output;
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
