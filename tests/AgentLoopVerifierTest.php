<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentLoopVerifier;

/**
 * Covers `--strict`: tasks/ and session_plan/ are the baseline inputs
 * `agent-loop verify` exists to confirm, so a missing directory becomes a
 * [FAIL] under --strict instead of the default [SKIP]. board (TODO.md) and
 * the learning root stay skippable even in strict mode -- both are
 * documented, opt-in additions on top of that baseline loop (see
 * README.md), not something every repo using `agent-loop` is expected to
 * have wired up.
 *
 * @internal
 */
final class AgentLoopVerifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-verifier-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultModeSkipsMissingTasksAndSessions(): void
    {
        $result = $this->verify([]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[SKIP] tasks: no directory at', $result['output']);
        self::assertStringContainsString('[SKIP] sessions: no directory at', $result['output']);
    }

    public function testStrictModeFailsWhenTasksAndSessionsAreMissing(): void
    {
        $result = $this->verify(['--strict']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] tasks: no directory at', $result['output']);
        self::assertStringContainsString('[FAIL] sessions: no directory at', $result['output']);
        self::assertStringContainsString('(required with --strict)', $result['output']);
        self::assertStringContainsString('[FAIL] agent-loop verify: drift detected, see above.', $result['output']);
    }

    public function testStrictModeStillSkipsBoardAndLearningRootOnceTasksAndSessionsExist(): void
    {
        mkdir($this->root . '/tasks', 0o775, true);
        file_put_contents($this->root . '/tasks/DEMO-1.md', "# Demo task\n\nBody.\n");
        mkdir($this->root . '/session_plan', 0o775, true);

        $result = $this->verify(['--strict']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[SKIP] board: no TODO.md', $result['output']);
        self::assertStringContainsString('[SKIP] learning root:', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    public function testRecallRootAutoDetectionAndCurrentFallback(): void
    {
        // 1. Create a tasks dir and a task file so checkTasks passes
        mkdir($this->root . '/tasks', 0o775, true);
        file_put_contents($this->root . '/tasks/TASK-1.md', "# TASK-1: Test Task\n\nBody.\n");

        // 2. Create session_plan with an active session for TASK-1
        mkdir($this->root . '/session_plan/2026-06-23-task-1', 0o775, true);
        $sessionData = [
            'id' => '2026-06-23-task-1',
            'task_id' => 'TASK-1',
            'status' => 'active',
            'claimed_by' => 'test-agent',
            'base_commit' => 'abcdef',
            'created_at' => '2026-06-23T10:00:00+02:00',
            'checkpoints' => []
        ];
        file_put_contents($this->root . '/session_plan/2026-06-23-task-1/session.json', json_encode($sessionData));

        // 3. Create a learning-root with recall-output/current/meta.json
        mkdir($this->root . '/infra/doc/agent-learning/recall-output/current', 0o775, true);
        $metaData = [
            'task_id' => 'TASK-1',
            'compilation_id' => 'compilation.TASK-1.123456',
            'output_hashes' => [
                'system.md' => hash('sha256', "# System Guidance")
            ]
        ];
        file_put_contents($this->root . '/infra/doc/agent-learning/recall-output/current/meta.json', json_encode($metaData));
        file_put_contents($this->root . '/infra/doc/agent-learning/recall-output/current/system.md', "# System Guidance");

        // 4. Run verify in strict mode.
        $result = $this->verify(['--strict']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] sessions: 1 session(s) parsed, 1 active and consistent', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function verify(array $tokens): array
    {
        ob_start();
        $exit = (new AgentLoopVerifier($this->root))->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
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
