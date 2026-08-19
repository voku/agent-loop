<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\SessionStore;

final class WorkflowPlanNextCommandTest extends TestCase
{
    /**
     * @return iterable<string, array{actor: string, rendered: string}>
     */
    public static function actorProvider(): iterable
    {
        yield 'simple value stays readable' => [
            'actor' => 'lars',
            'rendered' => 'lars',
        ];
        yield 'whitespace and parentheses' => [
            'actor' => 'Claude (Opus)',
            'rendered' => "'Claude (Opus)'",
        ];
        yield 'single quote' => [
            'actor' => "O'Reilly",
            'rendered' => "'O'\"'\"'Reilly'",
        ];
        yield 'shell metacharacters' => [
            'actor' => '$(printf injected); echo nope',
            'rendered' => "'$(printf injected); echo nope'",
        ];
    }

    #[DataProvider('actorProvider')]
    public function testPlanPrintsCopyPasteSafeActorThatRoundTripsThroughPosixShell(string $actor, string $rendered): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-next-command-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ABC-123',
                '--by', $actor,
                '--file', 'src/Foo.php',
                '--goal', 'Keep the next command executable.',
                '--validation', 'vendor/bin/phpunit',
            ]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit, $output);
            $expected = 'agent-loop workflow approve ABC-123 --by ' . $rendered;
            self::assertStringContainsString($expected, $output);
            self::assertSame($actor, $this->roundTripShellArgument($rendered));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testActiveSessionFailureExplainsTheGovernedReplanSupersessionHandoff(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-replan-handoff-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        try {
            $contracts = new TaskContractStore($root);
            $contracts->create('ABC-123', 'Initial scope.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
            $contracts->approve('ABC-123', 'lars');
            (new SessionStore())->create($root . '/.agent-loop/sessions', 'ABC-123', null, 'lars');

            $result = $this->runProcess([
                PHP_BINARY,
                dirname(__DIR__) . '/bin/agent-loop',
                'workflow', 'plan', 'ABC-123',
                '--by', 'lars',
                '--file', 'src/Foo.php',
                '--file', 'phpstan.neon',
                '--goal', 'Expanded scope.',
                '--validation', 'vendor/bin/phpunit',
            ], $root);

            self::assertSame(1, $result['exit'], $result['stderr']);
            self::assertSame(1, $contracts->load('ABC-123')->revision);
            self::assertStringContainsString('If durable intent is unchanged, continue the existing Run.', $result['stderr']);
            self::assertStringContainsString(
                'rerun `agent-loop workflow plan ABC-123 ... --supersede`',
                $result['stderr'],
            );
            self::assertStringContainsString('retires the current working Session', $result['stderr']);
            self::assertStringContainsString('still requires explicit approval before a replacement Run can start', $result['stderr']);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function roundTripShellArgument(string $rendered): string
    {
        $result = $this->runProcess(['sh', '-c', 'set -- ' . $rendered . '; printf %s "$1"']);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('POSIX shell probe failed: ' . $result['stderr']);
        }

        return $result['stdout'];
    }

    /**
     * @param list<string> $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runProcess(array $command, ?string $cwd = null): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start process probe.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($stdout === false || $stderr === false) {
            throw new RuntimeException('Unable to read process probe output.');
        }

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
