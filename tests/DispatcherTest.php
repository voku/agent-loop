<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dispatcher;

/**
 * @internal
 */
final class DispatcherTest extends TestCase
{
    /**
     * @param list<string>          $argv
     * @param non-empty-list<string> $expectedNeedles
     */
    private function assertRun(array $argv, int $expectedExit, array $expectedNeedles, string $root = '.'): void
    {
        $dispatcher = new Dispatcher($root);

        ob_start();
        $exit = $dispatcher->run($argv);
        $output = (string) ob_get_clean();

        self::assertSame($expectedExit, $exit);
        foreach ($expectedNeedles as $needle) {
            self::assertStringContainsString($needle, $output);
        }
    }

    public function testHelpIsPrintedAndSucceeds(): void
    {
        $this->assertRun(['agent-loop', 'help'], 0, ['agent-loop - unified CLI', 'board', 'learn', 'recall', 'memory']);
    }

    public function testNoArgumentsShowsHelp(): void
    {
        $this->assertRun(['agent-loop'], 0, ['agent-loop - unified CLI']);
    }

    public function testUnknownNamespaceFails(): void
    {
        $dispatcher = new Dispatcher('.');

        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'does-not-exist']);
        ob_end_clean();

        self::assertSame(1, $exit);
    }

    public function testBoardNamespaceRoutesToKanban(): void
    {
        // No subcommand -> TodoBoardCli prints its own usage and exits 0,
        // proving the `board` namespace reached voku/agent-kanban. An explicit
        // projectPrefix is injected so the usage path does not read a board file
        // from the test working directory.
        $dispatcher = new Dispatcher('.', null, 'TEST');

        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'board']);
        ob_end_clean();

        self::assertSame(0, $exit);
    }

    public function testLearnNamespaceRoutesToLearningCli(): void
    {
        $dispatcher = new Dispatcher('.');

        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'learn', 'help']);
        ob_end_clean();

        self::assertSame(0, $exit);
    }

    public function testRecallNamespaceRoutesToRecallCli(): void
    {
        $dispatcher = new Dispatcher('.');

        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'recall', 'help']);
        ob_end_clean();

        self::assertSame(0, $exit);
    }

    public function testMemoryNamespaceUsesDefaultRootFile(): void
    {
        $root = __DIR__ . '/fixtures/root-with-memory';

        $this->assertRun(
            ['agent-loop', 'memory', 'review'],
            0,
            ['MEMORY promotion review', 'Rows still needing promotion review: 1'],
            $root
        );
    }
}
