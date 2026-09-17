<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\WorkflowHookRunner;

final class WorkflowHookRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hooks-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReturnsEmptyWhenNoHooksConfigured(): void
    {
        $runner = new WorkflowHookRunner($this->root);
        $results = $runner->run('enter', 'TASK-1');

        self::assertSame([], $results);
    }

    public function testRunsHookConfiguredInWorkflowHooksJson(): void
    {
        $markerFile = $this->root . '/enter.marker';
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'touch-marker',
                    'command' => 'echo "$AGENT_LOOP_TASK_ID" > "' . $markerFile . '"',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $runner = new WorkflowHookRunner($this->root);
        $results = $runner->run('enter', 'TASK-123');

        self::assertCount(1, $results);
        self::assertSame('touch-marker', $results[0]['hook']);
        self::assertSame(0, $results[0]['exit_code']);
        self::assertNull($results[0]['error']);
        self::assertFileExists($markerFile);
        self::assertSame("TASK-123\n", file_get_contents($markerFile));
    }

    public function testFailClosedThrowsExceptionWhenCommandFails(): void
    {
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'failing-hook',
                    'command' => 'exit 42',
                    'fail_closed' => true,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $runner = new WorkflowHookRunner($this->root);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Workflow hook "failing-hook"');
        $runner->run('enter', 'TASK-FAIL');
    }

    public function testFailOpenRecordsFailureWithoutThrowing(): void
    {
        file_put_contents($this->root . '/.agent-loop/workflow-hooks.json', json_encode([
            'enter' => [
                [
                    'name' => 'failing-hook',
                    'command' => 'exit 42',
                    'fail_closed' => false,
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $runner = new WorkflowHookRunner($this->root);
        $results = $runner->run('enter', 'TASK-FAIL');

        self::assertCount(1, $results);
        self::assertSame(42, $results[0]['exit_code']);
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
