<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitInstallAssetsCommand;

/** @internal */
final class InitInstallAssetsCommandTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-install-assets-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        foreach (['CODEX_HOME', 'CODEX_SKILLS_DIR', 'CLAUDE_SKILLS_DIR', 'COPILOT_SKILLS_DIR', 'ANTIGRAVITY_SKILLS_DIR'] as $name) {
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

    public function testCodexDryRunUsesBundledAssetsWithoutWriting(): void
    {
        $result = $this->runCommand(['--agent=codex', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync skills: install agent-loop-discipline', $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync hooks: install hooks.json', $result['output']);
        self::assertStringContainsString('package-owned guidance validated; no files written', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.codex');
        self::assertStringNotContainsString('raw.githubusercontent.com', $result['output']);
        self::assertStringNotContainsString('plugin marketplace', strtolower($result['output']));
    }

    public function testCodexInstallsBundledSkillsAndHooks(): void
    {
        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-simplify-review/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-dogfood/SKILL.md');
        self::assertFileExists($this->root . '/.codex/hooks.json');
        self::assertFileExists($this->root . '/.codex/hooks/context.php');
        self::assertFileExists($this->root . '/.codex/hooks/pre_tool_use_policy.php');
        self::assertStringContainsString('without downloading remote code', $result['output']);
    }

    public function testClaudeInstallsSkillsWithoutCodexHooks(): void
    {
        $result = $this->runCommand(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.claude/skills/agent-loop-discipline/SKILL.md');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks.json');
        self::assertStringContainsString('hooks are currently available for codex only', $result['output']);
    }

    public function testAllInstallsSkillsForEveryAgentAndCodexHooksOnce(): void
    {
        $result = $this->runCommand(['--agent=all']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.claude/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.github/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.agents/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.codex/hooks/context.php');
    }

    public function testUnknownAgentFails(): void
    {
        self::assertSame(1, $this->runCommand(['--agent=nope'])['exit']);
    }

    public function testUnknownOptionFails(): void
    {
        self::assertSame(1, $this->runCommand(['--agent=codex', '--download'])['exit']);
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runCommand(array $tokens): array
    {
        ob_start();
        $exit = (new InitInstallAssetsCommand($this->root))->run($tokens);
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
