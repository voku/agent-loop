<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

/**
 * Covers the request-time resolution Dispatcher performs before delegating,
 * which exists specifically so the CLI doesn't require the caller to already
 * know an upstream package's internal id format or option defaults.
 *
 * @internal
 */
final class DispatcherResolutionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-resolution-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testBoardWithMissingMetadataFileEmitsNoWarning(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        }, E_WARNING);

        try {
            $exit = $this->dispatch(['agent-loop', 'board'])['exit'];
        } finally {
            restore_error_handler();
        }

        self::assertSame(0, $exit);
        self::assertSame([], $warnings, 'board must not warn when todo/board.md is absent');
    }

    public function testBoardHelpExitsCleanlyInsteadOfUnknownSubcommand(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'board', '--help'])['exit']);
        self::assertSame(0, $this->dispatch(['agent-loop', 'board', 'help'])['exit']);
    }

    public function testRecallCompileDefaultsOutputDirToTaskId(): void
    {
        $exit = $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'DEMO-1',
            '--file', 'src/Foo.php',
        ])['exit'];

        self::assertSame(0, $exit);
        self::assertFileExists($this->root . '/recall/DEMO-1/meta.json');
    }

    public function testRecallCompileLeavesExplicitOutputDirUntouched(): void
    {
        $exit = $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'DEMO-1',
            '--file', 'src/Foo.php',
            '--output-dir', $this->root . '/custom-dir',
        ])['exit'];

        self::assertSame(0, $exit);
        self::assertFileExists($this->root . '/custom-dir/meta.json');
        self::assertFileDoesNotExist($this->root . '/recall/DEMO-1/meta.json');
    }

    public function testSessionRecordAcceptsTaskIdInPlaceOfSessionId(): void
    {
        $sessionsRoot = $this->root . '/session_plan';
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--root', $sessionsRoot])['exit']);

        $sessionId = $this->onlySessionId($sessionsRoot);

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'session', 'record', 'DEMO-1',
            '--kind', 'decision', '--title', 'Keep scope tight',
            '--root', $sessionsRoot,
        ])['exit']);

        self::assertStringContainsString('Keep scope tight', (string) file_get_contents($sessionsRoot . '/' . $sessionId . '/decisions.md'));
    }

    public function testSessionCloseAcceptsTaskIdInPlaceOfSessionId(): void
    {
        $sessionsRoot = $this->root . '/session_plan';
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--root', $sessionsRoot])['exit']);

        $sessionId = $this->onlySessionId($sessionsRoot);

        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'close', 'DEMO-1', '--status', 'done', '--root', $sessionsRoot])['exit']);

        $metadata = json_decode((string) file_get_contents($sessionsRoot . '/' . $sessionId . '/session.json'), true);
        self::assertIsArray($metadata);
        self::assertSame('done', $metadata['status']);
    }

    public function testSessionRecordWithRealSessionIdStillWorksUnchanged(): void
    {
        $sessionsRoot = $this->root . '/session_plan';
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--root', $sessionsRoot])['exit']);

        $sessionId = $this->onlySessionId($sessionsRoot);

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'session', 'record', $sessionId,
            '--kind', 'decision', '--title', 'Direct id still works',
            '--root', $sessionsRoot,
        ])['exit']);
    }

    public function testSessionRecordWithUnknownIdStillFailsLikeBefore(): void
    {
        $sessionsRoot = $this->root . '/session_plan';
        mkdir($sessionsRoot, 0o775, true);

        $exit = $this->dispatch([
            'agent-loop', 'session', 'record', 'NOPE-404',
            '--kind', 'decision', '--title', 'Should not apply',
            '--root', $sessionsRoot,
        ])['exit'];

        self::assertSame(1, $exit);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{exit: int, output: string}
     */
    private function dispatch(array $argv): array
    {
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        $exit = $dispatcher->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function onlySessionId(string $sessionsRoot): string
    {
        $entries = array_values(array_diff(scandir($sessionsRoot) ?: [], ['.', '..']));
        self::assertCount(1, $entries);

        return $entries[0];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
