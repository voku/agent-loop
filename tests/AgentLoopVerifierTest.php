<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentLoopVerifier;
use voku\AgentSession\SessionStore;
use voku\AgentSession\WorkBriefStore;

/**
 * Covers `--strict`: `.agent-loop/tasks/` and `.agent-loop/sessions/` are the
 * baseline inputs `agent-loop verify` exists to confirm, so a missing directory
 * becomes a [FAIL] under --strict instead of the default [SKIP]. An optional
 * board and learning root stay skippable even in strict mode.
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
        self::assertStringContainsString('[OK] package delegates: board, learn, map, recall, review, session, workflow commands all resolve', $result['output']);
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
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/DEMO-1.md', "# Demo task\n\nBody.\n");
        mkdir($this->root . '/.agent-loop/sessions', 0o775, true);

        $result = $this->verify(['--strict']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[SKIP] board: no typed board source', $result['output']);
        self::assertStringContainsString('[SKIP] learning root:', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    /**
     * The escape hatch must be exactly one session wide. A flag that also silenced governed work
     * would not be a lifecycle distinction, it would be a way to switch the gate off.
     */
    public function testAnEphemeralSessionIsSkippedWhileGovernedWorkStillFailsTheSameGate(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-1.md', "# TASK-1: Test Task\n\nBody.\n");
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-2.md', "# TASK-2: Test Task\n\nBody.\n");

        $this->writeSession('2026-08-06-experiment', 'TASK-1', ephemeral: true);
        $result = $this->verify([]);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[SKIP] sessions: 2026-08-06-experiment is ephemeral', $result['output']);

        $this->writeSession('2026-08-06-governed', 'TASK-2', ephemeral: false);
        $result = $this->verify([]);
        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] recall: active session 2026-08-06-governed', $result['output']);
        self::assertStringNotContainsString('2026-08-06-governed is ephemeral', $result['output']);
    }

    private function writeSession(string $id, string $taskId, bool $ephemeral): void
    {
        mkdir($this->root . '/.agent-loop/sessions/' . $id, 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/sessions/' . $id . '/session.json',
            json_encode([
                'schema_version' => '1.0',
                'id' => $id,
                'task_id' => $taskId,
                'status' => 'active',
                'claimed_by' => 'test-agent',
                'base_commit' => 'abcdef',
                'created_at' => '2026-08-06T10:00:00+02:00',
                'checkpoints' => [],
                'ephemeral' => $ephemeral,
            ], JSON_THROW_ON_ERROR),
        );
    }

    public function testCompactRecallRootIsTheOnlyDefault(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-1.md', "# TASK-1: Test Task\n\nBody.\n");

        $this->writeSession('2026-06-23-task-1', 'TASK-1', ephemeral: false);

        mkdir($this->root . '/.agent-loop/recall/TASK-1', 0o775, true);
        $metaData = [
            'task_id' => 'TASK-1',
            'compilation_id' => 'compilation.TASK-1.123456',
            'output_hashes' => [
                'system.md' => hash('sha256', '# System Guidance'),
            ],
        ];
        file_put_contents($this->root . '/.agent-loop/recall/TASK-1/meta.json', json_encode($metaData));
        file_put_contents($this->root . '/.agent-loop/recall/TASK-1/system.md', '# System Guidance');

        $result = $this->verify(['--strict']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] sessions: 1 session(s) parsed, 1 active and consistent', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    public function testVerifyRecognizesTypedBoardMetadata(): void
    {
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `ABC`\n- **Done count:** 0\n");

        $result = $this->verify([]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] board: kanban board projection verified', $result['output']);
    }

    public function testVerifyFailsForApprovedBriefWithoutMatchingApprovalMetadata(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-1.md', "# TASK-1\n");
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'TASK-1');
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');
        unlink($session->path . '/approval.json');

        mkdir($this->root . '/.agent-loop/recall/TASK-1', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/recall/TASK-1/meta.json', '{}');

        $result = $this->verify(['--strict']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] work brief: session ' . $session->id . ' has approved revision 1 without matching approval metadata', $result['output']);
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
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
