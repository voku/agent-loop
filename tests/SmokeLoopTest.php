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

    public function testGovernedCompletionFlowUsesOnlyRecordedArtifacts(): void
    {
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'task.001', '--by', 'tester',
            '--learning-root', $this->root . '/learning-root', '--file', 'src/Signup.php',
            '--goal', 'Keep completion evidence auditable.',
            '--validation', 'vendor/bin/phpunit tests/SignupTest.php',
        ])['exit']);
        self::assertSame(0, $this->dispatch(['agent-loop', 'workflow', 'approve', 'task.001', '--by', 'tester'])['exit']);
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'session', 'validation', 'record', 'task.001',
            '--brief-revision', '1', '--command', 'vendor/bin/phpunit tests/SignupTest.php',
            '--status', 'passed', '--exit-code', '0', '--duration-ms', '12', '--by', 'tester', '--root', $this->root . '/session_plan',
        ])['exit']);
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'session', 'learning', 'decide', 'task.001',
            '--status', 'no_durable_learning', '--by', 'tester', '--root', $this->root . '/session_plan',
        ])['exit']);
        mkdir($this->root . '/.agent-recall/reviews', 0o775, true);
        file_put_contents($this->root . '/.agent-recall/reviews/task.001.blindspots.json', json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR));

        $context = $this->dispatch(['agent-loop', 'workflow', 'context', 'task.001']);
        self::assertSame(0, $context['exit']);
        self::assertStringContainsString('Keep completion evidence auditable.', $context['output']);
        self::assertStringContainsString('[passed] vendor/bin/phpunit tests/SignupTest.php', $context['output']);

        $report = $this->dispatch(['agent-loop', 'workflow', 'report', 'task.001']);
        self::assertSame(0, $report['exit']);
        self::assertStringContainsString('[passed] vendor/bin/phpunit tests/SignupTest.php', $report['output']);
        self::assertStringContainsString('decision no_durable_learning', $report['output']);

        $close = $this->dispatch(['agent-loop', 'workflow', 'close', 'task.001', '--status', 'done']);
        self::assertSame(0, $close['exit'], $close['output']);
        self::assertStringContainsString('[OK] validation:', $close['output']);
        self::assertStringContainsString('[OK] learning decision:', $close['output']);
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
