<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

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

    private function roundTripShellArgument(string $rendered): string
    {
        $pipes = [];
        $process = proc_open(
            ['sh', '-c', 'set -- ' . $rendered . '; printf %s "$1"'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start POSIX shell probe.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0 || $stdout === false || $stderr === false) {
            throw new RuntimeException('POSIX shell probe failed: ' . ($stderr === false ? '' : $stderr));
        }

        return $stdout;
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
