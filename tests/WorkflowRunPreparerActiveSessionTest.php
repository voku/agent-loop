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
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowRunPreparer;
use voku\AgentMap\Inspect\MapReadiness;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

/**
 * Workflow session invariant: governed preparation has at most one active Session per task.
 *
 * Which Sessions are active is the Session owner's decision: ephemeral exploration Sessions
 * never are, and a second open governed Session is refused by the owner itself. Ephemeral
 * Sessions therefore never block governed preparation, and preparing again never allocates
 * another governed Session (#1123).
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
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Example.php', "<?php\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $git = 'git -C ' . escapeshellarg($this->root);
        exec("$git init -q && $git config user.email t@example.test && $git config user.name T && $git add . && $git commit -qm base", $output, $exit);
        if ($exit !== 0) {
            throw new RuntimeException('Unable to create the fixture Git repository.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEphemeralExplorationSessionsDoNotBlockGovernedPreparation(): void
    {
        $sessionsRoot = (new ProjectLayout($this->root))->sessionsRoot();
        $store = new SessionStore();
        $store->create($sessionsRoot, self::TASK_ID, 'explore-1', 'explorer', null, true);
        $store->create($sessionsRoot, self::TASK_ID, 'explore-2', 'explorer', null, true);

        (new WorkflowRunPreparer($this->root))->prepare($this->contract(), $this->mapReadiness());
        $run = (new GovernedRunStore($this->root))->find(self::TASK_ID);
        self::assertNotNull($run);
        $governed = $this->governedOpenSessions($store, $sessionsRoot);
        self::assertCount(1, $governed);
        self::assertSame($governed[0]->id, $run->sessionId);

        // Preparing again reuses the one governed Session instead of allocating another.
        (new WorkflowRunPreparer($this->root))->prepare($this->contract(), $this->mapReadiness());
        self::assertSame([$run->sessionId], array_map(static fn (Session $session): string => $session->id, $this->governedOpenSessions($store, $sessionsRoot)));
    }

    /** @return list<Session> */
    private function governedOpenSessions(SessionStore $store, string $sessionsRoot): array
    {
        return array_values(array_filter(
            $store->all($sessionsRoot),
            static fn (Session $session): bool => $session->taskId === self::TASK_ID && !$session->ephemeral && !$session->status->isClosed(),
        ));
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
        $contracts = new TaskContractStore($this->root);
        if (!is_file($contracts->path(self::TASK_ID))) {
            $contracts->create(self::TASK_ID, 'Prepare a governed Run next to exploration Sessions.', ['src/Example.php'], [], ['php -r "exit(0);"'], 'planner');
            $contracts->approve(self::TASK_ID, 'approver', $contracts->load(self::TASK_ID)->revision);
        }

        return $contracts->load(self::TASK_ID);
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
