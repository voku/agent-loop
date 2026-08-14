<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentLoopVerifier;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentSession\SessionStore;

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
        self::assertStringContainsString('[OK] package delegates:', $result['output']);
    }

    public function testStrictModeFailsWhenTasksAndSessionsAreMissing(): void
    {
        $result = $this->verify(['--strict']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] tasks: no directory at', $result['output']);
        self::assertStringContainsString('[FAIL] sessions: no directory at', $result['output']);
        self::assertStringContainsString('(required with --strict)', $result['output']);
    }

    public function testTaskScopeRequiresTheExactTaskInsteadOfPassingOnAnotherTask(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/ABC-1.md', "# ABC-1\n");

        $result = $this->verify(['--task-id=ABC-2']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '[FAIL] tasks: task ABC-2 does not exist at ' . $this->root . '/.agent-loop/tasks/ABC-2.md',
            $result['output'],
        );
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
    }

    public function testAnEphemeralSessionIsSkippedWhileGovernedWorkStillFailsTheSameGate(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-1.md', "# TASK-1\n");
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-2.md', "# TASK-2\n");

        $this->writeSession('2026-08-06-experiment', 'TASK-1', ephemeral: true);
        $result = $this->verify([]);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[SKIP] sessions: 2026-08-06-experiment is ephemeral', $result['output']);

        $this->writeSession('2026-08-06-governed', 'TASK-2', ephemeral: false);
        $result = $this->verify([]);
        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] recall: active session 2026-08-06-governed', $result['output']);
    }

    public function testCanonicalRecallRootSupportsCurrentFallback(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-1.md', "# TASK-1\n");
        $this->writeSession('2026-06-23-task-1', 'TASK-1', ephemeral: false);

        mkdir($this->root . '/.agent-loop/recall/current', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/current/meta.json',
            json_encode([
                'task_id' => 'TASK-1',
                'compilation_id' => 'compilation.TASK-1.123456',
                'output_hashes' => ['system.md' => hash('sha256', '# System Guidance')],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($this->root . '/.agent-loop/recall/current/system.md', '# System Guidance');

        $result = $this->verify(['--strict']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] sessions: 1 session(s) parsed, 1 active and consistent', $result['output']);
    }

    public function testVerifyRecognizesTypedBoardMetadata(): void
    {
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** `ABC`\n");

        $result = $this->verify([]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[OK] board: kanban board projection verified', $result['output']);
    }

    public function testTaskScopeIgnoresAnotherCardsLocalDriftButFullVerifyDoesNot(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/ABC-1.md', "# ABC-1\n");
        file_put_contents($this->root . '/.agent-loop/todo/board.md', "# Board Metadata\n\n- **Project prefix:** ABC\n");
        file_put_contents($this->root . '/.agent-loop/todo/cards/ABC-1.md', <<<'MD'
# ABC-1: Valid card

- **Ticket:** ABC-1
- **Lane:** BACKLOG
- **Status:** Backlog
MD
            . "\n");
        file_put_contents($this->root . '/.agent-loop/todo/cards/XYZ-2.md', <<<'MD'
# XYZ-2: Unrelated invalid-prefix card

- **Ticket:** XYZ-2
- **Lane:** BACKLOG
- **Status:** Backlog
MD
            . "\n");

        $scoped = $this->verify(['--task-id=ABC-1']);
        self::assertSame(0, $scoped['exit'], $scoped['output']);
        self::assertStringContainsString('[OK] board: task ABC-1 and board-wide policy verified', $scoped['output']);

        $full = $this->verify([]);
        self::assertSame(1, $full['exit'], $full['output']);
        self::assertStringContainsString('[FAIL] board:', $full['output']);
    }

    public function testVerifyFailsWhenRunNoLongerMatchesCurrentApprovedContract(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/TASK-1.md', "# TASK-1\n");

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'TASK-1', by: 'lars');
        $contracts = new TaskContractStore($this->root);
        $contract = $contracts->create('TASK-1', 'Keep the scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        $approved = $contracts->approve('TASK-1', 'lars');
        (new GovernedRunStore($this->root))->prepare($approved, $session, $this->root . '/learning-root');

        mkdir($this->root . '/.agent-loop/recall/TASK-1', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/recall/TASK-1/meta.json', json_encode(['output_hashes' => []], JSON_THROW_ON_ERROR));

        $contracts->revise(
            'TASK-1',
            $contract->goal . ' revised',
            $contract->scope,
            $contract->nonGoals,
            $contract->validation,
            'lars',
        );

        $result = $this->verify(['--strict']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('has no current approved durable Contract', $result['output']);
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
                'updated_at' => '2026-08-06T10:00:00+02:00',
                'checkpoints' => [],
                'ephemeral' => $ephemeral,
            ], JSON_THROW_ON_ERROR),
        );
        foreach (['plan.md', 'assumptions.md', 'decisions.md', 'validation.md'] as $file) {
            file_put_contents($this->root . '/.agent-loop/sessions/' . $id . '/' . $file, '');
        }
        mkdir($this->root . '/.agent-loop/sessions/' . $id . '/checkpoints', 0o775, true);
    }

    /**

     * @param list<string> $tokens

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
