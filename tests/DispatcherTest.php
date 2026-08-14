<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

final class DispatcherTest extends TestCase
{
    /**
     * @param list<string>           $argv
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
        $this->assertRun(['agent-loop', 'help'], 0, ['agent-loop - unified CLI', 'edit', 'board', 'learn', 'map', 'recall', 'prompt', 'memory', 'workflow', 'init']);
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

    public function testPackageNamespacesRouteToTheirOwners(): void
    {
        foreach ([
            ['board'],
            ['learn', 'help'],
            ['recall', 'help'],
            ['prompt', 'guidance-gaps'],
            ['session', 'help'],
        ] as $args) {
            ob_start();
            $exit = (new Dispatcher('.'))->run(['agent-loop', ...$args]);
            ob_end_clean();
            self::assertSame(0, $exit, implode(' ', $args));
        }
    }

    public function testWorkflowNamespaceRoutesToGovernedWorkflowCli(): void
    {
        $dispatcher = new Dispatcher('.');
        ob_start();
        $exit = $dispatcher->run(['agent-loop', 'workflow', 'help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop workflow plan', $output);
        self::assertStringContainsString('agent-loop workflow close', $output);
        self::assertStringNotContainsString('agent-loop workflow start', $output);
    }

    public function testInitEditAndMapNamespacesRoute(): void
    {
        $this->assertRun(['agent-loop', 'init', 'help'], 0, ['agent-loop init doctor', 'sync-skills', 'install-plan']);
        $this->assertRun(['agent-loop', 'edit', 'help'], 0, ['agent-loop edit CLASS::METHOD', '--runner-command']);
        $this->assertRun(['agent-loop', 'map', 'help'], 0, ['agent-map - compact PHP symbol maps', 'agent-map query']);
    }

    public function testMapBuildQueryAndRefreshUseDispatcherRootDefaults(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-map-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o775, true);
        file_put_contents($root . '/src/LoopMapService.php', $this->mapFixture('LoopMapService'));

        try {
            $this->assertRun(
                ['agent-loop', 'map', 'build', '--paths=src'],
                0,
                ['Wrote 1 file(s),', $root . '/.agent-loop/map/php-symbols.json'],
                $root,
            );
            $this->assertRun(
                ['agent-loop', 'map', 'query', 'LoopMapService'],
                0,
                ['src/LoopMapService.php', 'class Demo\Loop\LoopMapService'],
                $root,
            );

            file_put_contents($root . '/src/LoopMapSecond.php', $this->mapFixture('LoopMapSecond'));
            $this->assertRun(['agent-loop', 'map', 'refresh'], 0, ['Refreshed 1 changed'], $root);
            $this->assertRun(['agent-loop', 'map', 'stale'], 0, ['OK'], $root);
            $this->assertRun(['agent-loop', 'map', 'query', 'LoopMapSecond'], 0, ['src/LoopMapSecond.php'], $root);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testConfiguredStateRootMovesTheWholeMapTree(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-map-root-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o775, true);
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/src/LoopMapService.php', $this->mapFixture('LoopMapService'));
        file_put_contents(
            $root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'paths' => ['state_root' => 'var/agent-state']], JSON_THROW_ON_ERROR),
        );

        try {
            $this->assertRun(
                ['agent-loop', 'map', 'build', '--paths=src'],
                0,
                ['Wrote 1 file(s),', $root . '/var/agent-state/map/php-symbols.json'],
                $root,
            );

            self::assertFileExists($root . '/var/agent-state/map/php-symbols.json');
            self::assertFileExists($root . '/var/agent-state/map/structural-cache.json');
            self::assertDirectoryExists($root . '/var/agent-state/map/phpstan-cache');
            self::assertDirectoryDoesNotExist($root . '/.agent-loop/map');
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMemoryNamespaceUsesDefaultRootFile(): void
    {
        $this->assertRun(
            ['agent-loop', 'memory', 'review'],
            0,
            ['MEMORY promotion review', 'Rows still needing promotion review: 1'],
            __DIR__ . '/fixtures/root-with-memory',
        );
    }

    private function mapFixture(string $class): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Demo\Loop;

        final class {$class}
        {
            public function run(): void
            {
            }
        }
        PHP;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
