<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The generated hooks run project checks inside the configured container. The
 * workdir must reach `cd` as data: interpolated into shell source, a quote in it
 * ended the path literal and could skip the configured check entirely.
 */
final class GitHooksContainerWorkdirTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hook-workdir-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/lib', 0o775, true);
        mkdir($this->root . '/bin', 0o775, true);
        copy(dirname(__DIR__) . '/resources/githooks/lib/agent-loop-hooks.sh', $this->root . '/lib/agent-loop-hooks.sh');

        // Stand-in for `docker`: reports a running compose service and executes
        // the `compose exec` payload locally, exactly as passed.
        file_put_contents($this->root . '/bin/docker', <<<'BASH'
            #!/usr/bin/env bash
            if [[ "$1 $2" == "compose ps" ]]; then echo stub-container; exit 0; fi
            if [[ "$1 $2" == "compose exec" ]]; then
                shift 2
                while [[ $# -gt 0 && "$1" != "php" ]]; do shift; done
                shift
                exec "$@"
            fi
            exit 1
            BASH);
        chmod($this->root . '/bin/docker', 0o755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testQuotedWorkdirReachesCdAsDataThroughCompose(): void
    {
        $workdir = $this->root . "/work'dir; : #";
        mkdir($workdir);
        file_put_contents($this->root . '/lib/agent-loop-hooks.env', implode("\n", [
            'AGENT_LOOP_CONTAINER_SERVICE=' . escapeshellarg('php'),
            'AGENT_LOOP_CONTAINER_WORKDIR=' . escapeshellarg($workdir),
        ]) . "\n");

        $output = $this->runHook('pwd > ran-in.txt && echo checked');

        self::assertSame("checked\n", $output);
        self::assertSame($workdir . "\n", (string) file_get_contents($workdir . '/ran-in.txt'));
    }

    public function testPlainWorkdirKeepsCommandSemantics(): void
    {
        $workdir = $this->root . '/app';
        mkdir($workdir);
        file_put_contents($this->root . '/lib/agent-loop-hooks.env', implode("\n", [
            'AGENT_LOOP_CONTAINER_SERVICE=' . escapeshellarg('php'),
            'AGENT_LOOP_CONTAINER_WORKDIR=' . escapeshellarg($workdir),
        ]) . "\n");

        self::assertSame("a b\n", $this->runHook('value="a b"; echo "$value"'));
    }

    public function testCheckoutAtTheDeclaredWorkdirIsRecognized(): void
    {
        $checkout = $this->gitCheckout('checkout');

        self::assertTrue($this->workdirIsCurrentCheckout($checkout, $checkout));
        // Running from a subdirectory still identifies the same checkout.
        mkdir($checkout . '/src');
        self::assertTrue($this->workdirIsCurrentCheckout($checkout . '/src', $checkout));
    }

    public function testDeclaredWorkdirReachedThroughASymlinkIsTheSameCheckout(): void
    {
        $checkout = $this->gitCheckout('checkout');
        symlink($checkout, $this->root . '/linked-workdir');

        self::assertTrue($this->workdirIsCurrentCheckout($checkout, $this->root . '/linked-workdir'));
    }

    public function testAnExistingDirectoryWithoutThisCheckoutIsNotTheDeclaredRuntime(): void
    {
        // A different container can have the declared path without this
        // repository behind it (#608): existing is not enough.
        $checkout = $this->gitCheckout('checkout');
        mkdir($this->root . '/other-project');

        self::assertFalse($this->workdirIsCurrentCheckout($checkout, $this->root . '/other-project'));
        self::assertFalse($this->workdirIsCurrentCheckout($checkout, $this->root . '/missing'));
    }

    private function gitCheckout(string $name): string
    {
        $path = $this->root . '/' . $name;
        mkdir($path);
        exec('git -C ' . escapeshellarg($path) . ' init --quiet 2>/dev/null');

        return $path;
    }

    private function workdirIsCurrentCheckout(string $cwd, string $workdir): bool
    {
        $script = 'source ' . escapeshellarg($this->root . '/lib/agent-loop-hooks.sh')
            . '; AGENT_LOOP_CONTAINER_WORKDIR=' . escapeshellarg($workdir)
            . '; agent_loop_hooks_workdir_is_current_checkout';
        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    private function runHook(string $command): string
    {
        $script = 'source ' . escapeshellarg($this->root . '/lib/agent-loop-hooks.sh')
            . '; agent_loop_hooks_run ' . escapeshellarg($command);
        $environment = getenv();
        $environment['PATH'] = $this->root . '/bin:' . ($environment['PATH'] ?? '/usr/bin:/bin');
        $process = proc_open(['bash', '-c', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $environment);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stderr);

        return $stdout;
    }
}
