<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

/**
 * End-to-end proof that `agent-loop` can drive task -> session -> recall ->
 * learn -> verify against tests/fixtures/basic-loop without any of the
 * underlying packages tripping over each other.
 *
 * @internal
 */
final class SmokeLoopTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-smoke-' . bin2hex(random_bytes(6));
        $this->copyDirectory(__DIR__ . '/fixtures/basic-loop', $this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFullLoopReportsNoDrift(): void
    {
        // session/recall/learn write straight to STDOUT (fwrite), not through
        // PHP's output buffer, so only their exit codes are asserted here;
        // their own packages cover output-text behavior in their own suites.
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester', '--root', $this->root . '/session_plan'])['exit'], 'session start');

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'task.001',
            '--file', 'src/Signup.php',
            '--output-dir', $this->root . '/recall/task.001',
        ])['exit'], 'recall compile');

        self::assertSame(0, $this->dispatch(['agent-loop', 'learn', 'validate', '--root', $this->root . '/learning-root'])['exit'], 'learn validate');

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] tasks: 1 task file(s) parsed: task.001', $result['output']);
        self::assertStringContainsString('[SKIP] board: no typed board source', $result['output']);
        self::assertStringContainsString('[OK] sessions: 1 session(s) parsed, 1 active and consistent', $result['output']);
        self::assertStringContainsString('[OK] learning root: validated', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    public function testVerifyFailsWhenActiveSessionHasNoRecallBriefing(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester', '--root', $this->root . '/session_plan'])['exit']);

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] recall: active session', $result['output']);
        self::assertStringContainsString('has no compiled briefing', $result['output']);
        self::assertStringContainsString('[FAIL] agent-loop verify: drift detected, see above.', $result['output']);
    }

    public function testVerifyFailsWhenRecallOutputIsTamperedWith(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester', '--root', $this->root . '/session_plan'])['exit']);

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'task.001',
            '--file', 'src/Signup.php',
            '--output-dir', $this->root . '/recall/task.001',
        ])['exit']);

        file_put_contents($this->root . '/recall/task.001/system.md', "tampered\n", FILE_APPEND);

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is stale (hash no longer matches meta.json)', $result['output']);
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

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0o775, true);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                mkdir($target, 0o775, true);
            } else {
                copy($item->getPathname(), $target);
            }
        }
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
