<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

/** End-to-end proof that the installed package boundaries cooperate. */
final class SmokeLoopTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-smoke-' . bin2hex(random_bytes(6));
        $this->copyDirectory(__DIR__ . '/fixtures/basic-loop', $this->root);
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        rename($this->root . '/tasks/task.001.md', $this->root . '/.agent-loop/tasks/task.001.md');
        rmdir($this->root . '/tasks');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testStandaloneSessionRecallAndLearningPackagesReportNoDrift(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester'])['exit']);

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'task.001',
            '--file', 'src/Signup.php',
        ])['exit']);

        self::assertSame(0, $this->dispatch(['agent-loop', 'learn', 'validate', '--root', $this->root . '/learning-root'])['exit']);

        $result = $this->dispatch([
            'agent-loop', 'verify',
            '--learning-root=' . $this->root . '/learning-root',
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] tasks: 1 task file(s) parsed: task.001', $result['output']);
        self::assertStringContainsString('[SKIP] board: no typed board source', $result['output']);
        self::assertStringContainsString('[OK] sessions: 1 session(s) parsed, 1 active and consistent', $result['output']);
        self::assertStringContainsString('[OK] learning root: validated', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    public function testVerifyFailsWhenActiveSessionHasNoRecallBriefing(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester'])['exit']);

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] recall: active session', $result['output']);
        self::assertStringContainsString('has no compiled briefing', $result['output']);
    }

    public function testVerifyFailsWhenRecallOutputIsTamperedWith(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'session', 'start', '--task', 'task.001', '--by', 'tester'])['exit']);

        self::assertSame(0, $this->dispatch([
            'agent-loop', 'recall', 'compile',
            '--root', $this->root . '/learning-root',
            '--task', 'task.001',
            '--file', 'src/Signup.php',
        ])['exit']);

        file_put_contents($this->root . '/.agent-loop/recall/task.001/system.md', "tampered\n", FILE_APPEND);

        $result = $this->dispatch(['agent-loop', 'verify']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('is stale (hash no longer matches meta.json)', $result['output']);
    }

    public function testGovernedCompletionFlowUsesOnlyRecordedOwnerArtifacts(): void
    {
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'plan', 'task.001', '--by', 'tester',
            '--learning-root', $this->root . '/learning-root', '--file', 'src/Signup.php',
            '--goal', 'Keep completion evidence auditable.',
            '--validation', 'vendor/bin/phpunit tests/SignupTest.php',
        ])['exit']);
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'approve', 'task.001', '--by', 'tester',
            '--learning-root', $this->root . '/learning-root',
        ])['exit']);
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'session', 'validation', 'record', 'task.001',
            '--contract-revision', '1', '--command', 'vendor/bin/phpunit tests/SignupTest.php',
            '--status', 'passed', '--exit-code', '0', '--duration-ms', '12', '--by', 'tester',
        ])['exit']);
        self::assertSame(0, $this->dispatch([
            'agent-loop', 'workflow', 'learn', 'task.001',
            '--status', 'no_durable_learning', '--by', 'tester',
            '--reason', 'The smoke run produced no reusable guidance.',
            '--learning-root', $this->root . '/learning-root',
        ])['exit']);
        mkdir($this->root . '/.agent-loop/recall/task.001/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/task.001/reviews/task.001.blindspots.json',
            json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
        );

        $context = $this->dispatch(['agent-loop', 'workflow', 'context', 'task.001', '--learning-root', $this->root . '/learning-root']);
        self::assertSame(0, $context['exit']);
        self::assertStringContainsString('Keep completion evidence auditable.', $context['output']);
        self::assertStringContainsString('[passed] vendor/bin/phpunit tests/SignupTest.php', $context['output']);

        $report = $this->dispatch([
            'agent-loop', 'workflow', 'report', 'task.001',
            '--learning-root', $this->root . '/learning-root',
        ]);
        self::assertSame(0, $report['exit']);
        self::assertStringContainsString('[passed] vendor/bin/phpunit tests/SignupTest.php via session', $report['output']);
        self::assertStringContainsString('Run decision no_durable_learning', $report['output']);

        $close = $this->dispatch([
            'agent-loop', 'workflow', 'close', 'task.001', '--status', 'done',
            '--learning-root', $this->root . '/learning-root',
        ]);
        self::assertSame(0, $close['exit'], $close['output']);
        self::assertStringContainsString('[OK] validation:', $close['output']);
        self::assertStringContainsString('[OK] learning decision:', $close['output']);
        self::assertStringContainsString('durable verification receipt persisted', $close['output']);
        self::assertFileExists($this->root . '/.agent-loop/runs/task.001/verification.json');
    }

    /** @param list<string> $argv @return array{exit: int, output: string} */
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
            RecursiveIteratorIterator::SELF_FIRST,
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
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
