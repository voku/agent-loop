<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitInstallAssetsCommand;
use voku\AgentLoop\Init\InitSyncSkillsCommand;

/**
 * @internal
 */
final class InitMultiSkillSourceTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-multi-skill-source-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        foreach (['CODEX_HOME', 'CODEX_SKILLS_DIR', 'CODEX_AGENTS_DIR'] as $name) {
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

    public function testSyncSkillsMergesMultipleRootsIntoOneManagedProjection(): void
    {
        $this->writeSkill('workflow-skills', 'workflow-discipline', '# Workflow');
        $this->writeSkill('engineering-skills', 'code-review-security', '# Security');

        $result = $this->runSync([
            '--agent=codex',
            '--skills-root=workflow-skills',
            '--skills-root=engineering-skills',
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/skills/workflow-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/code-review-security/SKILL.md');
        self::assertStringContainsString('synced 2 skill file(s) for codex', $result['output']);
        self::assertStringContainsString('from 2 source root(s)', $result['output']);

        $manifest = json_decode(
            (string) file_get_contents($this->root . '/.codex/skills/.agent-loop-manifest.json'),
            true,
            64,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame(2, $manifest['version']);
        self::assertSame(
            ['code-review-security', 'workflow-discipline'],
            array_column($manifest['entries'], 'target'),
        );
    }

    public function testSyncSkillsRejectsDuplicateCapabilityIdsAcrossRoots(): void
    {
        $this->writeSkill('workflow-skills', 'shared-skill', '# Workflow');
        $this->writeSkill('engineering-skills', 'shared-skill', '# Engineering');

        $result = $this->runSync([
            '--agent=codex',
            '--skills-root=workflow-skills',
            '--skills-root=engineering-skills',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('duplicate skill shared-skill', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.codex/skills');
    }

    public function testSyncSkillsRejectsMissingExplicitSourceInsteadOfSilentlyInstallingPartialSet(): void
    {
        $this->writeSkill('workflow-skills', 'workflow-discipline', '# Workflow');

        $result = $this->runSync([
            '--agent=codex',
            '--skills-root=workflow-skills',
            '--skills-root=missing-engineering-skills',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('source root does not exist: missing-engineering-skills', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.codex/skills');
    }

    public function testSyncSkillsRejectsUnreadableSourceBeforeMutatingManagedTargets(): void
    {
        $this->writeSkill('workflow-skills', 'workflow-discipline', '# Workflow');

        $unreadableRoot = $this->root . '/engineering-skills';
        mkdir($unreadableRoot, 0o775, true);
        self::assertTrue(chmod($unreadableRoot, 0o111));
        if (is_readable($unreadableRoot)) {
            self::markTestSkipped('Current test user can still read a 0111 directory.');
        }

        $staleSkill = $this->root . '/.codex/skills/stale/SKILL.md';
        mkdir(dirname($staleSkill), 0o775, true);
        file_put_contents($staleSkill, "# Existing managed target\n");
        $manifestPath = $this->root . '/.codex/skills/.agent-loop-manifest.json';
        $manifestBefore = <<<'JSON'
{
    "version": 1,
    "kind": "skills",
    "agent": "codex",
    "entries": [
        "stale"
    ]
}
JSON;
        file_put_contents($manifestPath, $manifestBefore . "\n");

        try {
            $result = $this->runSync([
                '--agent=codex',
                '--skills-root=workflow-skills',
                '--skills-root=engineering-skills',
            ]);
        } finally {
            chmod($unreadableRoot, 0o775);
        }

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('unable to read source root: engineering-skills', $result['output']);
        self::assertFileExists($staleSkill);
        self::assertSame($manifestBefore . "\n", file_get_contents($manifestPath));
        self::assertFileDoesNotExist($this->root . '/.codex/skills/workflow-discipline/SKILL.md');
    }

    public function testInstallAssetsIncludesLoopAndRecallOwnedSkillsWithoutExtraRoots(): void
    {
        ob_start();
        $exit = (new InitInstallAssetsCommand($this->root))->run([
            '--agent=codex',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-discipline/SKILL.md');
        $recallSkillPath = $this->root . '/.codex/skills/agent-recall-consumer/SKILL.md';
        self::assertFileExists($recallSkillPath);
        self::assertFileExists($this->root . '/.codex/skills/agent-recall-consumer/operating-prompts.json');

        $recallSkill = (string) file_get_contents($recallSkillPath);
        self::assertStringContainsString('<cwd>/.agent-loop/learning', $recallSkill);
        self::assertStringContainsString('<cwd>/.agent-loop/recall/<task-id>', $recallSkill);
        self::assertStringNotContainsString('infra/doc/agent-learning', $recallSkill);
        self::assertStringNotContainsString('.agent-recall-output', $recallSkill);

        self::assertStringContainsString('from 2 source root(s)', $output);
        self::assertStringContainsString('first-party package guidance', $output);
    }

    public function testInstallAssetsMergesFirstPartySkillsWithExplicitLocalEngineeringSkills(): void
    {
        $this->writeSkill('engineering-skills', 'code-review-security', '# Security');

        ob_start();
        $exit = (new InitInstallAssetsCommand($this->root))->run([
            '--agent=codex',
            '--extra-skills-root=engineering-skills',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/code-review-security/SKILL.md');
        self::assertStringContainsString('first-party package guidance plus 1 explicit local skill source(s)', $output);
        self::assertStringContainsString('without downloading remote code', $output);
    }

    private function writeSkill(string $root, string $name, string $body): void
    {
        $directory = $this->root . '/' . $root . '/' . $name;
        mkdir($directory, 0o775, true);
        file_put_contents($directory . '/SKILL.md', $body . "\n");
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runSync(array $tokens): array
    {
        ob_start();
        $exit = (new InitSyncSkillsCommand($this->root))->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
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
