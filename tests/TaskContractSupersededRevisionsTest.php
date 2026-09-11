<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

final class TaskContractSupersededRevisionsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-contract-history-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testATaskThatWasNeverRevisedHasNoHistoryRatherThanAnError(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('HIST-1', 'Original goal.', ['src/A.php'], [], ['composer ci'], 'lars');

        self::assertSame([], $contracts->supersededRevisions('HIST-1'));
    }

    public function testAnUnknownTaskHasNoHistory(): void
    {
        self::assertSame([], (new TaskContractStore($this->root))->supersededRevisions('HIST-404'));
    }

    public function testEachRevisionIsKeptWithTheApprovalItCarried(): void
    {
        // The approval on the superseded revision is the whole point: it is
        // what the human already agreed to, and what the next approval is
        // being asked to change.
        $contracts = new TaskContractStore($this->root);
        $contracts->create('HIST-2', 'Original goal.', ['src/A.php'], [], ['composer ci'], 'lars');
        $contracts->approve('HIST-2', 'approver-one');
        $contracts->revise('HIST-2', 'Wider goal.', ['src/A.php', 'src/B.php'], [], ['composer ci'], 'lars');

        $history = $contracts->supersededRevisions('HIST-2');

        self::assertCount(1, $history);
        self::assertSame(1, $history[0]->revision);
        self::assertSame(TaskContract::SUPERSEDED, $history[0]->status);
        self::assertSame('Original goal.', $history[0]->goal);
        self::assertSame(['src/A.php'], $history[0]->scope);
        self::assertSame('approver-one', $history[0]->approvedBy);

        // The current Contract stays the store's own answer, unchanged.
        $current = $contracts->load('HIST-2');
        self::assertSame(2, $current->revision);
        self::assertSame(TaskContract::CANDIDATE, $current->status);
    }

    public function testRevisionsComeBackOldestFirstBeyondNine(): void
    {
        // Ten revisions is where a plain string sort over `contract.NNN.json`
        // would still hold but a naive unpadded scheme would not; the order is
        // asserted on the revision numbers themselves, not on filenames.
        $contracts = new TaskContractStore($this->root);
        $contracts->create('HIST-3', 'Goal 1.', ['src/A.php'], [], ['composer ci'], 'lars');
        for ($revision = 2; $revision <= 11; ++$revision) {
            $contracts->revise('HIST-3', 'Goal ' . $revision . '.', ['src/A.php'], [], ['composer ci'], 'lars');
        }

        $history = $contracts->supersededRevisions('HIST-3');

        self::assertSame(
            range(1, 10),
            array_map(static fn (TaskContract $contract): int => $contract->revision, $history),
        );
        self::assertSame('Goal 1.', $history[0]->goal);
        self::assertSame('Goal 10.', $history[9]->goal);
    }

    public function testAnUnreadableArchivedRevisionFailsClosedInsteadOfShrinkingTheHistory(): void
    {
        // A silently skipped revision would understate what changed, which is
        // the one thing this projection exists to state.
        $contracts = new TaskContractStore($this->root);
        $contracts->create('HIST-4', 'Original goal.', ['src/A.php'], [], ['composer ci'], 'lars');
        $contracts->revise('HIST-4', 'Second goal.', ['src/A.php'], [], ['composer ci'], 'lars');
        $contracts->revise('HIST-4', 'Third goal.', ['src/A.php'], [], ['composer ci'], 'lars');

        $archived = dirname($contracts->path('HIST-4')) . '/history/contract.001.json';
        self::assertTrue(is_file($archived));
        file_put_contents($archived, '{ this is not valid json');

        $this->expectException(RuntimeException::class);
        $contracts->supersededRevisions('HIST-4');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
