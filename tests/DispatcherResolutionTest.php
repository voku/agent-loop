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

    public function testRecallCompileFollowUpClarifiesArtifactsAreNotAutoInjected(): void
    {
        $result = $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'DEMO-1',
            '--file', 'src/Foo.php',
        ]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('not automatically injected context', $result['output']);
        self::assertStringContainsString($this->root . '/recall/DEMO-1/system.md', $result['output']);
        self::assertStringContainsString('agent-loop does not inject this briefing into an agent session by itself', $result['output']);
        self::assertStringContainsString('agent-loop recall log-outcome --by <actor> --commit <sha>', $result['output']);
    }

    public function testRecallCompileFollowUpIsSkippedWhenCompileFails(): void
    {
        $result = $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertStringNotContainsString('not automatically injected context', $result['output']);
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

    public function testSessionRecordResolvesToTheOnlyActiveSessionAmongMultipleMatches(): void
    {
        $sessionsRoot = $this->root . '/session_plan';
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--slug', 'first-attempt', '--root', $sessionsRoot])['exit']);
        $closedSessionId = $this->onlySessionId($sessionsRoot);
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'close', $closedSessionId, '--status', 'dropped', '--root', $sessionsRoot])['exit']);

        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--slug', 'second-attempt', '--root', $sessionsRoot])['exit']);
        $activeSessionId = $this->onlyActiveSessionId($sessionsRoot, $closedSessionId);

        $result = $this->dispatch([
            'agent-loop', 'session', 'record', 'DEMO-1',
            '--kind', 'decision', '--title', 'Resolved to active session',
            '--root', $sessionsRoot,
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            'Resolved to active session',
            (string) file_get_contents($sessionsRoot . '/' . $activeSessionId . '/decisions.md'),
        );
        self::assertStringNotContainsString(
            'Resolved to active session',
            (string) file_get_contents($sessionsRoot . '/' . $closedSessionId . '/decisions.md'),
        );
    }

    public function testSessionRecordFailsClearlyWhenMultipleActiveSessionsMatchTask(): void
    {
        $sessionsRoot = $this->root . '/session_plan';
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--slug', 'first-attempt', '--root', $sessionsRoot])['exit']);
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--slug', 'second-attempt', '--root', $sessionsRoot])['exit']);

        // The ambiguity error goes through fwrite(STDERR, ...), like every
        // other Dispatcher-level error (see "Unknown command" in
        // printUsage()), so it is not visible to ob_start()-based capture
        // here. The exit code plus the absence of any write is what proves
        // the candidate was not silently guessed.
        $exit = $this->dispatch([
            'agent-loop', 'session', 'record', 'DEMO-1',
            '--kind', 'decision', '--title', 'Should not apply',
            '--root', $sessionsRoot,
        ])['exit'];

        self::assertSame(1, $exit);
        foreach (array_diff(scandir($sessionsRoot) ?: [], ['.', '..']) as $sessionId) {
            self::assertStringNotContainsString(
                'Should not apply',
                (string) file_get_contents($sessionsRoot . '/' . $sessionId . '/decisions.md'),
            );
        }
    }

    public function testSessionRecordFailsClearlyWhenMultipleNonActiveSessionsMatchTaskAndNoneIsActive(): void
    {
        $sessionsRoot = $this->root . '/session_plan';
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--slug', 'first-attempt', '--root', $sessionsRoot])['exit']);
        $firstSessionId = $this->onlySessionId($sessionsRoot);
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'close', $firstSessionId, '--status', 'dropped', '--root', $sessionsRoot])['exit']);

        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'DEMO-1', '--by', 'tester', '--slug', 'second-attempt', '--root', $sessionsRoot])['exit']);
        $secondSessionId = $this->onlyActiveSessionId($sessionsRoot, $firstSessionId);
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'close', $secondSessionId, '--status', 'done', '--root', $sessionsRoot])['exit']);

        $exit = $this->dispatch([
            'agent-loop', 'session', 'record', 'DEMO-1',
            '--kind', 'decision', '--title', 'Should not apply',
            '--root', $sessionsRoot,
        ])['exit'];

        self::assertSame(1, $exit);
        foreach (array_diff(scandir($sessionsRoot) ?: [], ['.', '..']) as $sessionId) {
            self::assertStringNotContainsString(
                'Should not apply',
                (string) file_get_contents($sessionsRoot . '/' . $sessionId . '/decisions.md'),
            );
        }
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

    private function onlyActiveSessionId(string $sessionsRoot, string $excludingSessionId): string
    {
        $entries = array_values(array_diff(scandir($sessionsRoot) ?: [], ['.', '..', $excludingSessionId]));
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
