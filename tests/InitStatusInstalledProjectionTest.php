<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitInstallAssetsCommand;
use voku\AgentLoop\Init\InitStatusCommand;

/** @internal */
final class InitStatusInstalledProjectionTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-installed-status-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        foreach (['CLAUDE_SKILLS_DIR', 'CLAUDE_AGENTS_DIR', 'CLAUDE_CONFIG_DIR'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }

        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testInstalledFirstPartyProjectionIsCurrentWithoutHostSourceTree(): void
    {
        ob_start();
        $installExit = (new InitInstallAssetsCommand($this->root))->run([
            '--agent=claude',
            '--skip-git-config',
        ]);
        $installOutput = (string) ob_get_clean();

        self::assertSame(0, $installExit, $installOutput);

        ob_start();
        $statusExit = (new InitStatusCommand($this->root))->run([]);
        $statusOutput = (string) ob_get_clean();

        self::assertSame(0, $statusExit, $statusOutput);
        self::assertStringContainsString('[OK] claude skills: current:', $statusOutput);
        self::assertStringContainsString('agent-loop-task-start', $statusOutput);
        self::assertStringContainsString('[OK] claude subagents: current:', $statusOutput);
        self::assertStringContainsString('agent-loop-code-reviewer.md', $statusOutput);
        self::assertStringContainsString('[OK] claude hooks: current:', $statusOutput);
        self::assertStringContainsString('hooks/runtime.php', $statusOutput);
        self::assertStringNotContainsString('[WARN] claude skills: stale managed entries', $statusOutput);
        self::assertStringNotContainsString('[WARN] claude subagents: stale managed entries', $statusOutput);
        self::assertStringNotContainsString('[WARN] claude hooks: stale managed entries', $statusOutput);
    }
}
