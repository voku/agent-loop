<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;

/**
 * `workflow status` can end by asking for an approval, so it has to say what is
 * being approved.
 *
 * The manifest already carries the goal and the acceptance criteria; the text
 * renderer used to drop both and show only `revision N (<path>)`. A human told
 * `Next: agent-loop workflow approve ... --by <named-actor>` was then given the
 * command and not the decision. Reported as #413.
 */
final class WorkflowStatusShowsTheDecisionSubjectTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-status-subject-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o700, true)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTheGoalBeingApprovedIsShown(): void
    {
        $this->planContract();

        $output = $this->render();

        self::assertStringContainsString('Render the decision subject in status output.', $output);
    }

    public function testTheAcceptanceCriteriaBeingApprovedAreShown(): void
    {
        $this->planContract();

        $output = $this->render();

        self::assertStringContainsString('The goal is visible without a second command.', $output);
        self::assertStringContainsString('A contract-less task still renders.', $output);
    }

    public function testTheSubjectIsShownAboveTheActionThatAsksForIt(): void
    {
        $this->planContract();

        $output = $this->render();

        $goal = strpos($output, 'Render the decision subject in status output.');
        $next = strpos($output, 'workflow approve');
        self::assertIsInt($goal);
        self::assertIsInt($next);
        self::assertLessThan(
            $next,
            $goal,
            'The goal must be readable before the line telling a human to approve it.',
        );
    }

    public function testATaskWithNoContractRendersWithoutAContractSection(): void
    {
        $output = $this->render();

        self::assertStringContainsString('Task ABC-404', $output);
        self::assertStringNotContainsString('Goal:', $output);
        self::assertStringNotContainsString('Acceptance criteria:', $output);
    }

    public function testAContractWithNoAcceptanceCriteriaStillShowsItsGoal(): void
    {
        (new TaskContractStore($this->root))->create(
            'ABC-404',
            'Render the decision subject in status output.',
            ['src/Workflow/WorkflowStatusCommand.php'],
            [],
            ['composer ci'],
            'claude',
        );

        $output = $this->render();

        self::assertStringContainsString('Render the decision subject in status output.', $output);
        self::assertStringNotContainsString('Acceptance criteria:', $output);
    }

    private function planContract(): void
    {
        (new TaskContractStore($this->root))->create(
            'ABC-404',
            'Render the decision subject in status output.',
            ['src/Workflow/WorkflowStatusCommand.php'],
            [],
            ['composer ci'],
            'claude',
            acceptanceCriteria: [
                'The goal is visible without a second command.',
                'A contract-less task still renders.',
            ],
        );
    }

    private function render(): string
    {
        ob_start();
        (new WorkflowStatusCommand($this->root))->run(['ABC-404']);

        return (string) ob_get_clean();
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
