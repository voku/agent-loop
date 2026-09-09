<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;

/**
 * Which governed Runs exist is a question the owner has to be able to answer.
 *
 * `find()` serves a caller that already knows the task id, which is every CLI
 * invocation. A consumer showing a project has no such list and falls back to
 * the board - and a task may carry a governed Run without a card, which `enter`
 * reports as a normal state. Those Runs are then not merely unrendered; they
 * are missing from the question being asked.
 */
final class GovernedRunEnumerationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-runs-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o700, true)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAProjectWithNoRunsRootReportsNoTasksRatherThanFailing(): void
    {
        self::assertSame([], (new GovernedRunStore($this->root))->taskIds());
    }

    public function testEveryTaskWithAGovernedRunIsReported(): void
    {
        $this->writeRun('UI-27');
        $this->writeRun('UI-1');
        $this->writeRun('LOOP-409');

        self::assertSame(['LOOP-409', 'UI-1', 'UI-27'], (new GovernedRunStore($this->root))->taskIds());
    }

    public function testTheOrderIsTheOwnersAndDoesNotFollowCreation(): void
    {
        // Two consumers listing "all governed work" must not disagree, so the
        // answer cannot inherit whatever order the filesystem happens to hand
        // back.
        foreach (['b', 'c', 'a'] as $taskId) {
            $this->writeRun($taskId);
        }

        self::assertSame(['a', 'b', 'c'], (new GovernedRunStore($this->root))->taskIds());
    }

    public function testSupersededRunsUnderTheHistoryRootAreNotReportedAsTasks(): void
    {
        $this->writeRun('UI-1');

        $history = (new ProjectLayout($this->root))->runHistoryRoot('UI-1');
        if (!mkdir($history, 0o700, true)) {
            throw new RuntimeException('Unable to create history fixture.');
        }
        file_put_contents($history . '/run.json', '{}');

        // `.history` is a sibling directory of the task directories and holds a
        // different question; reporting it as a task id would invent one.
        self::assertSame(['UI-1'], (new GovernedRunStore($this->root))->taskIds());
    }

    public function testTheParentDirectoryIsNeverReportedAsATask(): void
    {
        $this->writeRun('UI-1');

        // `runs/..` is the state root, and a file that happens to sit there is
        // reachable as `runs/../run.json`. Without the dot guard the entry `..`
        // satisfies the run-artifact check and is reported as a task id named
        // `..`, which no caller could ever resolve.
        file_put_contents(dirname((new ProjectLayout($this->root))->runsRoot()) . '/run.json', '{}');

        self::assertSame(['UI-1'], (new GovernedRunStore($this->root))->taskIds());
    }

    public function testADirectoryWithoutARunArtifactIsNotAGovernedRun(): void
    {
        $this->writeRun('UI-1');
        if (!mkdir((new ProjectLayout($this->root))->runRoot('UI-2'), 0o700, true)) {
            throw new RuntimeException('Unable to create leftover fixture.');
        }

        self::assertSame(['UI-1'], (new GovernedRunStore($this->root))->taskIds());
    }

    private function writeRun(string $taskId): void
    {
        $directory = (new ProjectLayout($this->root))->runRoot($taskId);
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create run fixture: ' . $directory);
        }
        file_put_contents($directory . '/run.json', '{}');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_link($full)) {
                unlink($full);
                continue;
            }
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
