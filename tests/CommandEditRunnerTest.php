<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\CommandEditRunner;
use voku\AgentLoop\Edit\EditExecution;
use voku\AgentLoop\Edit\EditRequest;

final class CommandEditRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-command-runner-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testPassesPromptThroughStdinWithoutAShell(): void
    {
        $prompt = $this->root . '/prompt.md';
        file_put_contents($prompt, 'deterministic prompt');
        $request = $this->request([
            '-n',
            '-r',
            '$input = stream_get_contents(STDIN); fwrite(STDOUT, "seen:" . $input);',
        ]);

        $result = (new CommandEditRunner())->run(new EditExecution($request, $prompt));

        self::assertSame('runner_succeeded', $result->status);
        self::assertSame(0, $result->exitCode);
        self::assertSame('seen:deterministic prompt', $result->stdout);
        self::assertSame('', $result->stderr);
    }

    public function testPreservesNonZeroExitAndStderr(): void
    {
        $prompt = $this->root . '/prompt.md';
        file_put_contents($prompt, 'prompt');
        $request = $this->request([
            '-n',
            '-r',
            'fwrite(STDERR, "runner failed"); exit(7);',
        ]);

        $result = (new CommandEditRunner())->run(new EditExecution($request, $prompt));

        self::assertSame('runner_failed', $result->status);
        self::assertSame(7, $result->exitCode);
        self::assertSame('runner failed', $result->stderr);
    }

    public function testTerminatesAStillRunningCommandAfterTimeout(): void
    {
        $prompt = $this->root . '/prompt.md';
        file_put_contents($prompt, 'prompt');
        $request = $this->request([
            '-n',
            '-r',
            'sleep(5);',
        ], 1);

        $startedAt = microtime(true);
        $result = (new CommandEditRunner())->run(new EditExecution($request, $prompt));

        self::assertSame('runner_failed', $result->status);
        self::assertSame(124, $result->exitCode);
        self::assertStringContainsString('Runner timed out after 1 seconds.', $result->stderr);
        self::assertLessThan(4.0, microtime(true) - $startedAt);
    }

    /** @param list<string> $arguments */
    private function request(array $arguments, int $timeoutSeconds = 10): EditRequest
    {
        return new EditRequest(
            taskId: 'TASK-1',
            target: 'Demo\\Service::run',
            instruction: 'Change it.',
            projectRoot: $this->root,
            recallRoot: $this->root,
            mapIndex: $this->root . '/map.json',
            mapRoot: $this->root,
            outputDirectory: $this->root . '/edit',
            runner: 'command',
            runnerCommand: PHP_BINARY,
            runnerArguments: $arguments,
            runnerTimeoutSeconds: $timeoutSeconds,
        );
    }
}
