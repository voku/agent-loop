<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GitHookPolicyTrustTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hook-policy-trust-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.githooks/lib', 0o775, true);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        mkdir($this->root . '/bin', 0o775, true);

        copy(dirname(__DIR__) . '/githooks/pre-commit', $this->root . '/.githooks/pre-commit');
        copy(dirname(__DIR__) . '/githooks/lib/agent-loop-hooks.sh', $this->root . '/.githooks/lib/agent-loop-hooks.sh');
        chmod($this->root . '/.githooks/pre-commit', 0o755);

        file_put_contents(
            $this->root . '/.agent-loop/githooks.json',
            json_encode(
                [
                    'pre_commit' => [
                        'checks' => [[
                            'name' => 'branch-controlled',
                            'command' => 'printf branch-controlled',
                        ]],
                    ],
                ],
                JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
            ) . "\n",
        );
        file_put_contents(
            $this->root . '/bin/fake-agent-loop',
            <<<'BASH'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$AGENT_LOOP_CAPTURE"
exit 0
BASH,
        );
        chmod($this->root . '/bin/fake-agent-loop', 0o755);

        $this->git(['init', '-b', 'main']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUnapprovedTrackedPolicyNeverReachesAgentLoopExecution(): void
    {
        $result = $this->runHook();

        self::assertNotSame(0, $result['exit']);
        self::assertFileDoesNotExist($this->root . '/capture.log');
        self::assertStringContainsString('policy', strtolower($result['stderr']));
        self::assertStringContainsString('sync-githooks', $result['stderr']);
    }

    public function testApprovedPolicyRunsUntilTrackedPolicyChanges(): void
    {
        $this->approveCurrentPolicy();

        $approved = $this->runHook();
        self::assertSame(0, $approved['exit'], $approved['stderr']);
        self::assertFileExists($this->root . '/capture.log');
        self::assertStringContainsString('githooks pre-commit', (string) file_get_contents($this->root . '/capture.log'));

        unlink($this->root . '/capture.log');
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{\"pre_commit\":{\"checks\":[]}}\n");

        $changed = $this->runHook();
        self::assertNotSame(0, $changed['exit']);
        self::assertFileDoesNotExist($this->root . '/capture.log');
        self::assertStringContainsString('policy', strtolower($changed['stderr']));
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

    /** @return array{exit: int, stdout: string, stderr: string} */
    private function runHook(): array
    {
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['AGENT_LOOP_BIN'] = $this->root . '/bin/fake-agent-loop';
        $environment['AGENT_LOOP_CAPTURE'] = $this->root . '/capture.log';

        $process = proc_open(
            ['bash', $this->root . '/.githooks/pre-commit'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start pre-commit hook.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
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
