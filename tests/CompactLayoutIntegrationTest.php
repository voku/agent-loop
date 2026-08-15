<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

/** @internal */
final class CompactLayoutIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-compact-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        file_put_contents($this->root . '/composer.json', "{\"name\": \"demo/compact\"}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testScaffoldKeepsWorkflowStateUnderOneDirectory(): void
    {
        $scaffold = $this->dispatch(['agent-loop', 'init', 'scaffold', '--demo']);

        self::assertSame(0, $scaffold['exit'], $scaffold['output']);
        self::assertFileExists($this->root . '/.agent-loop/init.json');
        self::assertFileExists($this->root . '/.agent-loop/todo/board.md');
        self::assertFileExists($this->root . '/.agent-loop/todo/cards/DEMO-1.md');
        self::assertFileExists($this->root . '/.agent-loop/tasks/DEMO-1.md');
        self::assertDirectoryExists($this->root . '/.agent-loop/sessions');
        self::assertDirectoryExists($this->root . '/.agent-loop/learning/findings');

        foreach (['todo', 'tasks', 'session_plan', 'infra/doc/agent-learning', '.agent-map'] as $legacyPath) {
            self::assertFileDoesNotExist($this->root . '/' . $legacyPath);
            self::assertDirectoryDoesNotExist($this->root . '/' . $legacyPath);
        }

        $config = json_decode((string) file_get_contents($this->root . '/.agent-loop/init.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('layout', $config);

        $show = $this->dispatch(['agent-loop', 'board', 'card', 'show', 'DEMO-1']);
        self::assertSame(0, $show['exit'], $show['output']);

        $plan = $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'DEMO-1', '--by', 'tester',
            '--file', 'composer.json', '--goal', 'Prove compact layout.', '--validation', 'composer test',
        ]);
        self::assertSame(0, $plan['exit'], $plan['output']);
        self::assertSame([], glob($this->root . '/.agent-loop/sessions/*') ?: []);
        self::assertDirectoryDoesNotExist($this->root . '/session_plan');

        $approve = $this->dispatch(['agent-loop', 'workflow', 'approve', 'DEMO-1', '--by', 'tester']);
        self::assertSame(0, $approve['exit'], $approve['output']);
        self::assertNotSame([], glob($this->root . '/.agent-loop/sessions/*') ?: []);
        self::assertFileExists($this->root . '/.agent-loop/recall/DEMO-1/meta.json');

        $verify = $this->dispatch(['agent-loop', 'verify']);
        self::assertSame(0, $verify['exit'], $verify['output']);
        self::assertStringContainsString('[OK] board:', $verify['output']);
        self::assertStringContainsString('[OK] sessions:', $verify['output']);
    }

    /**
     * @param list<string> $argv
     * @return array{exit: int, output: string}
     */
    private function dispatch(array $argv): array
    {
        ob_start();
        try {
            $exit = (new Dispatcher($this->root))->run($argv);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return ['exit' => $exit, 'output' => $output];
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
