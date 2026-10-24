<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;

final class WorkflowApproveRevisionBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-approve-revision-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/docs', 0o775, true) && !is_dir($this->root . '/docs')) {
            throw new RuntimeException('Unable to create revision-binding fixture.');
        }
        file_put_contents($this->root . '/docs/note.txt', "current\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testApproveWithMatchingRevisionSucceeds(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'APPROVE-REV-1',
            'Approve one bounded text change bound to its reviewed revision.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        ob_start();
        try {
            $exit = (new WorkflowApproveCommand($this->root))->run(['APPROVE-REV-1', '--by', 'approver', '--revision', '1']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, $output);
        self::assertSame(TaskContract::APPROVED, $contracts->load('APPROVE-REV-1')->status);
    }

    public function testApproveRefusesWhenRevisionChangedSinceReview(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'APPROVE-REV-2',
            'Approve one bounded text change bound to a stale reviewed revision.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
        $contracts->revise(
            'APPROVE-REV-2',
            'Approve one bounded text change bound to a stale reviewed revision (revised).',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        ob_start();
        try {
            // The caller reviewed revision 1, but the Contract moved on to revision 2
            // (an actual stale review, not a future revision that never existed).
            $exit = (new WorkflowApproveCommand($this->root))->run(['APPROVE-REV-2', '--by', 'approver', '--revision', '1']);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertSame(TaskContract::CANDIDATE, $contracts->load('APPROVE-REV-2')->status);
        self::assertSame(2, $contracts->load('APPROVE-REV-2')->revision);
    }

    public function testRevisionOptionRejectsNonPositiveValue(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'APPROVE-REV-3',
            'Approve one bounded text change with an invalid revision flag.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        ob_start();
        try {
            $exit = (new WorkflowApproveCommand($this->root))->run(['APPROVE-REV-3', '--by', 'approver', '--revision', '0']);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        self::assertSame(TaskContract::CANDIDATE, $contracts->load('APPROVE-REV-3')->status);
    }

    public function testApproveWithStaleRevisionRefusesEvenWhenAlreadyApprovedAtADifferentRevision(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'APPROVE-REV-5',
            'Approve one bounded text change already approved at a different revision.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
        $contracts->approve('APPROVE-REV-5', 'first-approver');

        ob_start();
        try {
            $exit = (new WorkflowApproveCommand($this->root))->run(['APPROVE-REV-5', '--by', 'approver', '--revision', '2']);
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
    }

    public function testApproveWithoutRevisionOptionStillSucceeds(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'APPROVE-REV-4',
            'Approve one bounded text change without the optional revision flag.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );

        ob_start();
        try {
            $exit = (new WorkflowApproveCommand($this->root))->run(['APPROVE-REV-4', '--by', 'approver']);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(TaskContract::APPROVED, $contracts->load('APPROVE-REV-4')->status);
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
