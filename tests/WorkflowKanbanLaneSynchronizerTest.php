<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Domain\CardRevision;
use voku\AgentKanban\Domain\Claim;
use voku\AgentKanban\Domain\Lane;
use voku\AgentKanban\Mutation\CardMutationService;
use voku\AgentKanban\Repository\BoardContextResolver;
use voku\AgentLoop\Workflow\KanbanLaneSynchronizer;
use voku\AgentLoop\Workflow\TaskContract;

final class WorkflowKanbanLaneSynchronizerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-kanban-sync-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/todo/kanban.config.json',
            json_encode([
                'projectPrefix' => 'ABC',
                'cardDirectory' => 'todo/cards',
                'lanes' => ['BACKLOG', 'READY', 'DOING', 'VERIFY', 'BLOCKED', 'DONE'],
                'transitions' => [
                    'BACKLOG' => ['READY'],
                    'READY' => ['DOING', 'BLOCKED'],
                    'DOING' => ['VERIFY', 'BLOCKED', 'DONE'],
                    'VERIFY' => ['DOING', 'DONE'],
                    'BLOCKED' => ['READY', 'DOING', 'BACKLOG'],
                ],
                'requiredFieldsByLane' => [
                    'READY' => ['taskBrief'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSynchronizesBacklogToReadyOnWorkStart(): void
    {
        $cardFile = $this->root . '/.agent-loop/todo/cards/ABC-1.md';
        file_put_contents($cardFile, <<<'CARD'
# ABC-1: Test task

- **Ticket:** ABC-1
- **Lane:** BACKLOG
- **Status:** Selected
- **Summary:** Summary of test task
CARD
            . "\n");

        $contract = new TaskContract(
            taskId: 'ABC-1',
            goal: 'Implement automated kanban sync',
            scope: ['src/Workflow/KanbanLaneSynchronizer.php'],
            nonGoals: [],
            validation: ['composer test'],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-11-11T12:00:00Z',
            updatedAt: '2026-11-11T12:00:00Z',
            path: '.agent-loop/contracts/ABC-1/contract-1.json',
            plannedBy: 'test-planner',
            approvedBy: 'test-approver',
            approvedAt: '2026-11-11T12:05:00Z',
        );

        $synchronizer = new KanbanLaneSynchronizer($this->root);
        $result = $synchronizer->synchronizeWorkStart($contract);

        self::assertNotNull($result);
        self::assertTrue($result->mutated);
        self::assertSame('BACKLOG', $result->previousLane);
        self::assertSame('READY', $result->currentLane);
        self::assertNull($result->warning);

        $cardContent = file_get_contents($cardFile);
        self::assertIsString($cardContent);
        self::assertStringContainsString('- **Lane:** READY', $cardContent);
        self::assertStringContainsString('Implement automated kanban sync', $cardContent);
    }

    public function testSynchronizesBacklogToDoingWhenDirectTransitionAllowed(): void
    {
        $customRoot = sys_get_temp_dir() . '/agent-loop-direct-doing-' . bin2hex(random_bytes(8));
        mkdir($customRoot . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents(
            $customRoot . '/.agent-loop/todo/kanban.config.json',
            json_encode([
                'projectPrefix' => 'DIR',
                'cardDirectory' => 'todo/cards',
                'lanes' => ['BACKLOG', 'DOING'],
                'transitions' => [
                    'BACKLOG' => ['DOING'],
                    'DOING' => ['BACKLOG'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $cardFile = $customRoot . '/.agent-loop/todo/cards/DIR-1.md';
        file_put_contents($cardFile, <<<'CARD'
# DIR-1: Direct move task

- **Ticket:** DIR-1
- **Lane:** BACKLOG
- **Status:** To Do
- **Summary:** Direct transition test
CARD
            . "\n");

        $contract = new TaskContract(
            taskId: 'DIR-1',
            goal: 'Direct goal',
            scope: [],
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-11-11T12:00:00Z',
            updatedAt: '2026-11-11T12:00:00Z',
            path: '.agent-loop/contracts/DIR-1/contract-1.json',
            plannedBy: 'test-planner',
            approvedBy: 'test-approver',
            approvedAt: '2026-11-11T12:05:00Z',
        );

        $synchronizer = new KanbanLaneSynchronizer($customRoot);
        $result = $synchronizer->synchronizeWorkStart($contract);

        self::assertNotNull($result);
        self::assertTrue($result->mutated);
        self::assertSame('BACKLOG', $result->previousLane);
        self::assertSame('DOING', $result->currentLane);

        $this->removeDirectory($customRoot);
    }

    public function testIdempotentWhenAlreadyInDoing(): void
    {
        $cardFile = $this->root . '/.agent-loop/todo/cards/ABC-2.md';
        file_put_contents($cardFile, <<<'CARD'
# ABC-2: Already doing

- **Ticket:** ABC-2
- **Lane:** DOING
- **Status:** Selected

## Agent Task Brief

Already has brief.
CARD
            . "\n");

        $contract = new TaskContract(
            taskId: 'ABC-2',
            goal: 'Already doing goal',
            scope: [],
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-11-11T12:00:00Z',
            updatedAt: '2026-11-11T12:00:00Z',
            path: '.agent-loop/contracts/ABC-2/contract-1.json',
            plannedBy: 'test-planner',
            approvedBy: 'test-approver',
            approvedAt: '2026-11-11T12:05:00Z',
        );

        $synchronizer = new KanbanLaneSynchronizer($this->root);
        $result = $synchronizer->synchronizeWorkStart($contract);

        self::assertNotNull($result);
        self::assertFalse($result->mutated);
        self::assertSame('DOING', $result->previousLane);
        self::assertSame('DOING', $result->currentLane);
        self::assertNull($result->warning);
    }

    public function testDoesNotMoveCardBackwardsWhenInVerify(): void
    {
        $cardFile = $this->root . '/.agent-loop/todo/cards/ABC-3.md';
        file_put_contents($cardFile, <<<'CARD'
# ABC-3: In verify

- **Ticket:** ABC-3
- **Lane:** VERIFY
- **Status:** Selected

## Agent Task Brief

Already verified.
CARD
            . "\n");

        $contract = new TaskContract(
            taskId: 'ABC-3',
            goal: 'Verify goal',
            scope: [],
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-11-11T12:00:00Z',
            updatedAt: '2026-11-11T12:00:00Z',
            path: '.agent-loop/contracts/ABC-3/contract-1.json',
            plannedBy: 'test-planner',
            approvedBy: 'test-approver',
            approvedAt: '2026-11-11T12:05:00Z',
        );

        $synchronizer = new KanbanLaneSynchronizer($this->root);
        $result = $synchronizer->synchronizeWorkStart($contract);

        self::assertNotNull($result);
        self::assertFalse($result->mutated);
        self::assertSame('VERIFY', $result->currentLane);
    }

    public function testClaimConflictRetainsSourceLane(): void
    {
        $cardFile = $this->root . '/.agent-loop/todo/cards/ABC-4.md';
        file_put_contents($cardFile, <<<'CARD'
# ABC-4: Claimed by another

- **Ticket:** ABC-4
- **Lane:** BACKLOG
- **Status:** Selected
- **Claim:** other-agent|claimed=2026-11-11T12:00:00+00:00|expires=-|rev=0000000000000000000000000000000000000000000000000000000000000000

## Agent Task Brief

Brief here.
CARD
            . "\n");

        $contract = new TaskContract(
            taskId: 'ABC-4',
            goal: 'Claimed goal',
            scope: [],
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-11-11T12:00:00Z',
            updatedAt: '2026-11-11T12:00:00Z',
            path: '.agent-loop/contracts/ABC-4/contract-1.json',
            plannedBy: 'test-planner',
            approvedBy: 'test-approver',
            approvedAt: '2026-11-11T12:05:00Z',
        );

        $synchronizer = new KanbanLaneSynchronizer($this->root);
        $result = $synchronizer->synchronizeWorkStart($contract, actor: 'my-agent');

        self::assertNotNull($result);
        self::assertFalse($result->mutated);
        self::assertSame('BACKLOG', $result->currentLane);
        self::assertNotNull($result->warning);
        self::assertStringContainsString('is already claimed by "other-agent"', $result->warning);
    }

    public function testUnpermittedTransitionRetainsSourceLaneWithWarning(): void
    {
        // Custom board where BACKLOG has no transition to READY or DOING
        $customRoot = sys_get_temp_dir() . '/agent-loop-blocked-trans-' . bin2hex(random_bytes(8));
        mkdir($customRoot . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents(
            $customRoot . '/.agent-loop/todo/kanban.config.json',
            json_encode([
                'projectPrefix' => 'ISO',
                'cardDirectory' => 'todo/cards',
                'lanes' => ['BACKLOG', 'DOING'],
                'transitions' => [
                    'BACKLOG' => [], // Dead end!
                    'DOING' => [],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        file_put_contents($customRoot . '/.agent-loop/todo/cards/ISO-1.md', <<<'CARD'
# ISO-1: Isolated card

- **Ticket:** ISO-1
- **Lane:** BACKLOG
- **Status:** Selected
CARD
            . "\n");

        $contract = new TaskContract(
            taskId: 'ISO-1',
            goal: 'Isolated goal',
            scope: [],
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-11-11T12:00:00Z',
            updatedAt: '2026-11-11T12:00:00Z',
            path: '.agent-loop/contracts/ISO-1/contract-1.json',
            plannedBy: 'test-planner',
            approvedBy: 'test-approver',
            approvedAt: '2026-11-11T12:05:00Z',
        );

        try {
            $synchronizer = new KanbanLaneSynchronizer($customRoot);
            $result = $synchronizer->synchronizeWorkStart($contract);

            self::assertNotNull($result);
            self::assertFalse($result->mutated);
            self::assertSame('BACKLOG', $result->currentLane);
            self::assertNotNull($result->warning);
            self::assertStringContainsString('no permitted transition path', $result->warning);
        } finally {
            $this->removeDirectory($customRoot);
        }
    }

    public function testNoCardOrUnconfiguredBoardReturnsNullCleanly(): void
    {
        $contract = new TaskContract(
            taskId: 'adhoc-task-123',
            goal: 'Adhoc goal',
            scope: [],
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-11-11T12:00:00Z',
            updatedAt: '2026-11-11T12:00:00Z',
            path: '.agent-loop/contracts/adhoc-task-123/contract-1.json',
            plannedBy: 'test-planner',
            approvedBy: 'test-approver',
            approvedAt: '2026-11-11T12:05:00Z',
        );

        $synchronizer = new KanbanLaneSynchronizer($this->root);
        $result = $synchronizer->synchronizeWorkStart($contract);
        self::assertNull($result);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $path . '/' . $item;
            if (is_dir($target)) {
                $this->removeDirectory($target);
            } else {
                unlink($target);
            }
        }

        rmdir($path);
    }
}
