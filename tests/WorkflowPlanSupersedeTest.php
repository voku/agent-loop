<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class WorkflowPlanSupersedeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-plan-supersede-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create supersession fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testPlanRefusesToReviseAnActiveGovernedSessionWithoutExplicitSupersession(): void
    {
        $contracts = $this->approvedContract();
        $sessions = new SessionStore();
        $session = $sessions->create((new ProjectLayout($this->root))->sessionsRoot(), 'SUPERSEDE-1', by: 'agent');

        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run($this->revisionArguments());
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertSame(SessionStatus::ACTIVE, $sessions->load(dirname($session->path), $session->id)->status);
        $contract = $contracts->load('SUPERSEDE-1');
        self::assertSame(1, $contract->revision);
        self::assertSame(TaskContract::APPROVED, $contract->status);
    }

    public function testExplicitSupersessionRetiresWorkingSessionAndCreatesUnapprovedCandidateRevision(): void
    {
        $contracts = $this->approvedContract();
        $sessions = new SessionStore();
        $session = $sessions->create((new ProjectLayout($this->root))->sessionsRoot(), 'SUPERSEDE-1', by: 'agent');

        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run([
                ...$this->revisionArguments(),
                '--supersede',
            ]);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(SessionStatus::DROPPED, $sessions->load(dirname($session->path), $session->id)->status);

        $candidate = $contracts->load('SUPERSEDE-1');
        self::assertSame(2, $candidate->revision);
        self::assertSame(TaskContract::CANDIDATE, $candidate->status);
        self::assertSame('Revised intent after L2 rejection.', $candidate->goal);
        self::assertNull($candidate->approvedBy);
        self::assertNull($candidate->approvedAt);
        self::assertStringContainsString('candidate Contract superseded', $output);
        self::assertStringContainsString('agent-loop workflow approve SUPERSEDE-1 --by planner', $output);
    }

    public function testSupersedeCannotCreateAFirstContract(): void
    {
        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run([
                ...$this->revisionArguments(),
                '--supersede',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertNull((new TaskContractStore($this->root))->find('SUPERSEDE-1'));
    }

    private function approvedContract(): TaskContractStore
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'SUPERSEDE-1',
            'Original approved intent.',
            ['src/Foo.php'],
            [],
            ['composer ci'],
            'planner',
        );
        $contracts->approve('SUPERSEDE-1', 'approver');

        return $contracts;
    }

    /** @return list<string> */
    private function revisionArguments(): array
    {
        return [
            'SUPERSEDE-1',
            '--by', 'planner',
            '--file', 'src/Foo.php',
            '--goal', 'Revised intent after L2 rejection.',
            '--validation', 'composer ci',
        ];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
