<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitDoctorCommand;
use voku\AgentLoop\Init\InitInstallAssetsCommand;
use voku\AgentLoop\Init\InitStatusCommand;

/** @internal */
final class InitDiagnosticConsistencyTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-consistency-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        foreach ([
            'CODEX_HOME',
            'CODEX_SKILLS_DIR',
            'CODEX_AGENTS_DIR',
            'CLAUDE_CONFIG_DIR',
            'CLAUDE_SKILLS_DIR',
            'CLAUDE_AGENTS_DIR',
            'OPENCODE_SKILLS_DIR',
            'OPENCODE_AGENTS_DIR',
            'COPILOT_SKILLS_DIR',
            'COPILOT_AGENTS_DIR',
            'GEMINI_SKILLS_DIR',
            'GEMINI_AGENTS_DIR',
            'ANTIGRAVITY_SKILLS_DIR',
            'ANTIGRAVITY_AGENTS_DIR',
        ] as $name) {
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

    public function testFreshClaudeInstallKeepsDoctorAndStatusAssetStateConsistent(): void
    {
        $install = $this->capture(
            fn (): int => (new InitInstallAssetsCommand($this->root))->run(['--agent=claude']),
        );
        self::assertSame(0, $install['exit'], $install['output']);

        $doctor = $this->capture(
            fn (): int => (new InitDoctorCommand($this->root))->run([]),
        );
        $status = $this->capture(
            fn (): int => (new InitStatusCommand($this->root))->run([]),
        );

        self::assertSame(0, $doctor['exit'], $doctor['output']);
        self::assertSame(0, $status['exit'], $status['output']);

        self::assertStringContainsString('[OK] Managed assets [claude skills]: current=', $doctor['output']);
        self::assertStringContainsString('[OK] Managed assets [claude subagents]: current=', $doctor['output']);
        self::assertStringNotContainsString('[WARN] Managed assets [claude skills]:', $doctor['output']);
        self::assertStringNotContainsString('[WARN] Managed assets [claude subagents]:', $doctor['output']);

        self::assertStringContainsString('[OK] claude skills: no stale managed entries', $status['output']);
        self::assertStringContainsString('[OK] claude subagents: no stale managed entries', $status['output']);
        self::assertStringNotContainsString('[WARN] claude skills: stale', $status['output']);
        self::assertStringNotContainsString('[WARN] claude subagents: stale', $status['output']);
    }

    /**
     * @param callable(): int $command
     * @return array{exit: int, output: string}
     */
    private function capture(callable $command): array
    {
        ob_start();
        $exit = $command();
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
