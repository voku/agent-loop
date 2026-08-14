<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitStatusCommand;
use voku\AgentLoop\Init\InitSyncHooksCommand;
use voku\AgentLoop\PathResolver;

/**
 * One owner decides what a host asset path means.
 *
 * `init status` reports where an asset target is and `init sync-hooks` writes
 * there. While each carried its own answer they agreed on Unix by accident and
 * disagreed everywhere else: status resolved a drive-letter `CODEX_HOME` as the
 * absolute path it is, sync-hooks appended it to the project root.
 *
 * @internal
 */
final class HostAssetPathOwnershipTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-host-paths-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        foreach (['CODEX_HOME', 'CLAUDE_CONFIG_DIR'] as $name) {
            $this->envBackup[$name] = getenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }

        $this->removeDirectory($this->root);
    }

    public function testStatusAndSyncHooksAgreeOnAWindowsAbsoluteCodexHome(): void
    {
        putenv('CODEX_HOME=C:\\Users\\me\\.codex');

        $status = $this->capture(fn (): int => (new InitStatusCommand($this->root))->run([]));
        $sync = $this->capture(fn (): int => (new InitSyncHooksCommand($this->root))->run([
            '--agent=codex',
            '--hooks-root=' . dirname(__DIR__) . '/docs/agents/codex-hooks',
            '--dry-run',
        ]));

        self::assertStringContainsString('C:/Users/me/.codex', $status, 'init status lost the drive letter.');
        self::assertStringContainsString('C:/Users/me/.codex', $sync, 'init sync-hooks lost the drive letter.');
        self::assertStringNotContainsString(
            $this->root . '/C:',
            $sync,
            'init sync-hooks joined an absolute Windows CODEX_HOME onto the project root.',
        );
    }

    public function testAnEnvironmentDirectoryRelativeToTheProjectStaysInsideIt(): void
    {
        putenv('CODEX_HOME=vendor-codex/');

        self::assertSame(
            $this->root . '/vendor-codex',
            PathResolver::fromEnvironment($this->root, 'CODEX_HOME'),
        );
    }

    public function testAnUnsetOrBlankEnvironmentDirectoryHasNoPath(): void
    {
        putenv('CODEX_HOME');
        self::assertNull(PathResolver::fromEnvironment($this->root, 'CODEX_HOME'));

        putenv('CODEX_HOME=   ');
        self::assertNull(PathResolver::fromEnvironment($this->root, 'CODEX_HOME'));
    }

    public function testStoredArtifactReferencesAreRenderedByTheSameOwner(): void
    {
        self::assertSame(
            'runs/ABC-123/run.json',
            PathResolver::relativeTo('C:\\project', 'C:\\project\\runs\\ABC-123\\run.json'),
            'a stored Run reference kept a host-specific separator instead of a portable path.',
        );
        self::assertSame(
            '/elsewhere/learning',
            PathResolver::relativeTo('/project', '/elsewhere/learning'),
            'a path outside the project must stay absolute rather than be mangled.',
        );
    }

    /** @param callable(): int $command */
    private function capture(callable $command): string
    {
        ob_start();

        try {
            $command();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
            $path = $directory . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
