<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The package make fragment's default AGENT_LOOP_RUN delegates to the generated
 * Git hook runtime, so workflow targets run in the declared container without
 * host Makefile code.
 */
final class MakeFragmentRuntimeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (trim((string) shell_exec('command -v make')) === '') {
            self::markTestSkipped('make is not available.');
        }

        $this->root = sys_get_temp_dir() . '/agent-loop-make-runtime-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/bin', 0o775, true);

        // Stand-in for the agent-loop CLI: records where it ran and what it got.
        file_put_contents($this->root . '/bin/fake-agent-loop', <<<'BASH'
            #!/usr/bin/env bash
            { printf '%s\n' "$PWD"; printf '%s\n' "$@"; } > ran.txt
            BASH);
        chmod($this->root . '/bin/fake-agent-loop', 0o755);

        // Stand-in for docker: a running compose service whose `exec` runs locally.
        file_put_contents($this->root . '/bin/docker', <<<'BASH'
            #!/usr/bin/env bash
            if [[ "$1 $2" == "compose ps" ]]; then echo stub-container; exit 0; fi
            if [[ "$1 $2" == "compose exec" ]]; then
                echo compose >> "$(dirname "$0")/docker.log"
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

    public function testWorkflowTargetRunsInTheDeclaredContainer(): void
    {
        $workdir = $this->root . '/container-workdir';
        mkdir($workdir);
        $this->generateHookRuntime($workdir);
        $this->writeMakefile();

        $this->make(['agent_workflow_plan', 'TASK=T-1', 'FILE=src/Foo.php', 'BY=me', "GOAL=it's a \"goal\" with  spaces"]);

        self::assertFileExists($this->root . '/bin/docker.log');
        self::assertSame(
            [$workdir, 'workflow', 'plan', 'T-1', '--by', 'me', '--file', 'src/Foo.php', '--goal', "it's a \"goal\" with  spaces", '--validation', 'composer ci'],
            $this->recordedRun($workdir),
        );
    }

    public function testWithoutGeneratedRuntimeTheTargetRunsOnTheHost(): void
    {
        $this->writeMakefile();

        $this->make(['agent_workflow_status', 'TASK=T-2']);

        self::assertFileDoesNotExist($this->root . '/bin/docker.log');
        self::assertSame([$this->root, 'workflow', 'status', 'T-2'], $this->recordedRun($this->root));
    }

    public function testHostDefinedRunnerStillWins(): void
    {
        $this->generateHookRuntime($this->root . '/container-workdir');
        $this->writeMakefile("define AGENT_LOOP_RUN\necho host-runner $(2)\nendef\n");

        $output = $this->make(['agent_workflow_status', 'TASK=T-3']);

        self::assertStringContainsString('host-runner agent_workflow_status', $output);
        self::assertFileDoesNotExist($this->root . '/bin/docker.log');
    }

    private function generateHookRuntime(string $workdir): void
    {
        mkdir($this->root . '/.githooks/lib', 0o775, true);
        copy(dirname(__DIR__) . '/resources/githooks/lib/agent-loop-hooks.sh', $this->root . '/.githooks/lib/agent-loop-hooks.sh');
        file_put_contents($this->root . '/.githooks/lib/agent-loop-hooks.env', implode("\n", [
            'AGENT_LOOP_CONTAINER_SERVICE=' . escapeshellarg('php'),
            'AGENT_LOOP_CONTAINER_WORKDIR=' . escapeshellarg($workdir),
        ]) . "\n");
    }

    private function writeMakefile(string $prelude = ''): void
    {
        file_put_contents($this->root . '/Makefile', $prelude
            . 'AGENT_LOOP_BIN := ' . $this->root . "/bin/fake-agent-loop\n"
            . 'include ' . dirname(__DIR__) . "/resources/make/agent-loop.mk\n");
    }

    /**
     * @param list<string> $arguments
     */
    private function make(array $arguments): string
    {
        $environment = getenv();
        $environment['PATH'] = $this->root . '/bin:' . ($environment['PATH'] ?? '/usr/bin:/bin');
        $process = proc_open(['make', '--no-print-directory', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $environment);
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);

        return $stdout;
    }

    /** @return list<string> */
    private function recordedRun(string $directory): array
    {
        self::assertFileExists($directory . '/ran.txt');

        return explode("\n", rtrim((string) file_get_contents($directory . '/ran.txt'), "\n"));
    }
}
