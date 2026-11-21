<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowRunPreparer;
use voku\AgentMap\Inspect\MapReadiness;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

/**
 * Workflow session invariant: governed preparation has at most one active Session per task.
 *
 * When more than one active Session already exists, preparation must fail before it binds
 * a governed Run or allocates yet another Session, instead of silently picking one.
 */
final class WorkflowRunPreparerActiveSessionTest extends TestCase
{
    private const TASK_ID = 'SESSION-INVARIANT-1';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-active-session-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o700, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPreparationRefusesSeveralActiveSessionsForTheTask(): void
    {
        $sessionsRoot = (new ProjectLayout($this->root))->sessionsRoot();
        $store = new SessionStore();
        // The owner refuses a second non-ephemeral open Session, so the ambiguous
        // state is built from two open ephemeral Sessions; both count as active.
        $store->create($sessionsRoot, self::TASK_ID, 'first', 'planner', null, true);
        $store->create($sessionsRoot, self::TASK_ID, 'second', 'planner', null, true);
        $before = $this->sessionIds($store, $sessionsRoot);
        self::assertCount(2, $before);

        try {
            (new WorkflowRunPreparer($this->root))->prepare($this->contract(), $this->mapReadiness());
            self::fail('Preparation selected one of several active Sessions instead of refusing.');
        } catch (RuntimeException $exception) {
            self::assertSame('Multiple active Sessions found for ' . self::TASK_ID . '.', $exception->getMessage());
        }

        self::assertNull((new GovernedRunStore($this->root))->find(self::TASK_ID));
        self::assertSame($before, $this->sessionIds($store, $sessionsRoot));
    }

    /** @return list<string> */
    private function sessionIds(SessionStore $store, string $sessionsRoot): array
    {
        $ids = array_map(static fn (Session $session): string => $session->id, $store->all($sessionsRoot));
        sort($ids);

        return $ids;
    }

    private function contract(): TaskContract
    {
        return new TaskContract(
            taskId: self::TASK_ID,
            goal: 'Prepare a governed Run while the Session state is ambiguous.',
            scope: ['src/Example.php'],
            nonGoals: [],
            validation: ['composer ci'],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-09-15T00:00:00+00:00',
            updatedAt: '2026-09-15T00:00:00+00:00',
            path: $this->root . '/contract.json',
            plannedBy: 'planner',
            approvedBy: 'approver',
            approvedAt: '2026-09-15T00:00:00+00:00',
        );
    }

    private function mapReadiness(): MapReadiness
    {
        return new MapReadiness(
            mapState: 'missing',
            mapPath: $this->root . '/.agent-loop/map/php-symbols.json',
            mapSnapshot: null,
            staleEntries: [],
            searchState: 'unavailable',
            searchPath: $this->root . '/.agent-loop/map/search.sqlite',
            searchSnapshot: null,
        );
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
