<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\GitHooks\GitHooksCli;

final class GitHookPolicyTrustTest extends TestCase
{
    private string $root;

    private string $marker;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hook-policy-trust-' . bin2hex(random_bytes(8));
        $this->marker = $this->root . '/branch-command-executed';
        mkdir($this->root . '/.agent-loop', 0o775, true);

        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.email', 'agent-loop@example.test']);
        $this->git(['config', 'user.name', 'Agent Loop Test']);
        file_put_contents($this->root . '/tracked.php', "<?php\n");
        $this->git(['add', 'tracked.php']);
        $this->git(['commit', '-m', 'initial']);
        file_put_contents($this->root . '/tracked.php', "<?php\n// staged change\n");
        $this->git(['add', 'tracked.php']);

        $this->writePolicy($this->markerCommand());
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUnapprovedTrackedPolicyCannotReachCommandExecutionBoundary(): void
    {
        $exit = (new GitHooksCli($this->root))->run(['pre-commit']);

        self::assertNotSame(0, $exit);
        self::assertFileDoesNotExist($this->marker);
    }

    public function testApprovedPolicyRunsUntilTrackedPolicyChanges(): void
    {
        $this->approveCurrentPolicy();

        $approvedExit = (new GitHooksCli($this->root))->run(['pre-commit']);
        self::assertSame(0, $approvedExit);
        self::assertFileExists($this->marker);

        unlink($this->marker);
        $this->writePolicy($this->markerCommand(), 'branch-controlled-changed');

        $changedExit = (new GitHooksCli($this->root))->run(['pre-commit']);
        self::assertNotSame(0, $changedExit);
        self::assertFileDoesNotExist($this->marker);
    }

    public function testMissingTrackedPolicyRemainsANoOpWithoutApproval(): void
    {
        unlink($this->root . '/.agent-loop/githooks.json');

        $exit = (new GitHooksCli($this->root))->run(['pre-commit']);

        self::assertSame(0, $exit);
        self::assertFileDoesNotExist($this->marker);
    }

    private function markerCommand(): string
    {
        $code = 'file_put_contents(' . var_export($this->marker, true) . ', "executed");';

        return escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code);
    }

    private function writePolicy(string $command, string $name = 'branch-controlled'): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/githooks.json',
            json_encode(
                [
                    'pre_commit' => [
                        'checks' => [[
                            'name' => $name,
                            'command' => $command,
                        ]],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n",
        );
    }

    private function approveCurrentPolicy(): void
    {
        $gitPath = $this->gitOutput(['rev-parse', '--git-path', 'agent-loop/githooks-policy.sha256']);
        $approvalPath = $this->absoluteGitPath($gitPath);
        $directory = dirname($approvalPath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create policy approval directory.');
        }
        $hash = hash_file('sha256', $this->root . '/.agent-loop/githooks.json');
        self::assertIsString($hash);
        file_put_contents($approvalPath, $hash . "\n");
    }

    /** @param list<string> $args */
    private function git(array $args): void
    {
        $this->gitOutput($args);
    }

    /** @param list<string> $args */
    private function gitOutput(array $args): string
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start git.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: 'git failed');
        }

        return trim((string) $stdout);
    }

    private function absoluteGitPath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->root . '/' . $path;
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDirectory($path . '/' . $entry);
        }
        rmdir($path);
    }
}
