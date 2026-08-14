<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\Init\InitDoctorCommand;
use voku\AgentLoop\Init\InitSyncGitHooksCommand;

/**
 * A linked worktree is a real checkout whose `.git` is a file.
 *
 * `is_dir($root . '/.git')` reported such a checkout as "not a repository":
 * `init doctor` warned that Git was missing and `init sync-githooks` skipped
 * `core.hooksPath`, so it installed hook files Git was never pointed at. Agents
 * work in linked worktrees routinely, so the detection is pinned here against a
 * worktree this test actually creates.
 *
 * @internal
 */
final class GitWorkTreeDetectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (self::git('--version') === null) {
            self::markTestSkipped('git is not available.');
        }

        $this->root = sys_get_temp_dir() . '/agent-loop-worktree-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testALinkedWorktreeIsRecognisedAsAGitWorkingTree(): void
    {
        $main = $this->repository();
        $linked = $this->root . '/linked';
        self::git('-C', $main, 'worktree', 'add', '--detach', '--quiet', $linked);

        self::assertFileExists($linked . '/.git');
        self::assertFileDoesNotExist($linked . '/.git/HEAD', 'A linked worktree stores .git as a file, not a directory.');

        self::assertTrue(GitWorkTree::detected($main));
        self::assertTrue(GitWorkTree::detected($linked));
    }

    public function testAPlainDirectoryIsNotAGitWorkingTree(): void
    {
        $plain = $this->root . '/plain';
        mkdir($plain, 0o775, true);

        self::assertFalse(GitWorkTree::detected($plain));
        self::assertFalse(GitWorkTree::detected($this->root . '/missing'));
    }

    public function testDoctorReportsGitInsideALinkedWorktree(): void
    {
        $linked = $this->linkedWorktree();

        ob_start();
        (new InitDoctorCommand($linked))->run([]);
        $output = (string) ob_get_clean();

        self::assertStringContainsString('[OK] Git: working tree detected', $output);
    }

    public function testSyncGitHooksConfiguresGitInsideALinkedWorktree(): void
    {
        $linked = $this->linkedWorktree();

        ob_start();
        $exitCode = (new InitSyncGitHooksCommand($linked))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exitCode, $output);
        self::assertStringContainsString('core.hooksPath=', $output);
        self::assertStringNotContainsString('skipped core.hooksPath', $output);
        self::assertSame('.githooks', self::git('-C', $linked, 'config', '--get', 'core.hooksPath'));
    }

    private function linkedWorktree(): string
    {
        $main = $this->repository();
        $linked = $this->root . '/linked';
        self::git('-C', $main, 'worktree', 'add', '--detach', '--quiet', $linked);

        return $linked;
    }

    /** Creates a repository with one commit so a worktree can be added from it. */
    private function repository(): string
    {
        $main = $this->root . '/main';
        mkdir($main, 0o775, true);
        self::git('-C', $main, 'init', '--quiet', '--initial-branch=main');
        self::git('-C', $main, 'config', 'user.email', 'dogfood@example.invalid');
        self::git('-C', $main, 'config', 'user.name', 'agent-loop dogfood');
        self::git('-C', $main, 'config', 'commit.gpgsign', 'false');
        file_put_contents($main . '/README.md', "worktree fixture\n");
        self::git('-C', $main, 'add', 'README.md');
        self::git('-C', $main, 'commit', '--quiet', '--no-gpg-sign', '-m', 'fixture');

        return $main;
    }

    private static function git(string ...$arguments): ?string
    {
        $command = ['git'];
        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($stdout) ? trim($stdout) : null;
    }

    private function rm(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rm($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
