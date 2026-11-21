<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowLearningRecorder;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/**
 * A passing review's non-blocking findings are records, not PASS-summary prose.
 * In real use (php-runtime-audit #46, agent-loop #1119) reviewers saw defects,
 * rated them "known limit"/nit, and nothing reached Learning.
 *
 * @internal
 */
final class ReviewObservationCaptureTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-observations-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $git = 'git -C ' . escapeshellarg($this->root);
        exec("$git init -q && $git config user.email tests@example.test && $git config user.name Tests && $git add src/Foo.php .gitignore && $git commit -qm base", $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        $baseCommit = trim((string) shell_exec("$git rev-parse HEAD"));

        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'ABC-123', '--by', 'lars', '--file', 'src/Foo.php',
            '--goal', 'Prove review observations are captured.',
            '--validation', 'php -l src/Foo.php', '--base-commit', $baseCommit,
        ]));
        $revision = (new TaskContractStore($this->root))->load('ABC-123')->revision;
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--revision', (string) $revision, '--by', 'lars']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run(['ABC-123', '--profile', 'surgical', '--by', 'lars']));
        self::assertSame(0, (new HostFrontDoorCommand($this->root))->run('enter', ['ABC-123', '--format=json']));
        ob_end_clean();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testStageResultSerializesObservationsOnlyWhenPresent(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        $plain = new StageResult('s', 'T', 'r', 1, $digest, 'review', 1, StageOutcome::PASS, 'c', [], [], 'ok');
        self::assertArrayNotHasKey('observations', $plain->toArray());

        $observed = new StageResult('s', 'T', 'r', 1, $digest, 'review', 1, StageOutcome::PASS, 'c', [], [], 'ok', observations: [' max(1, ..) admits a target at budget 0 ']);
        self::assertSame(['max(1, ..) admits a target at budget 0'], $observed->toArray()['observations']);
    }

    public function testStageResultRejectsEmptyOrUnboundedObservations(): void
    {
        $digest = 'sha256:' . str_repeat('a', 64);
        try {
            new StageResult('s', 'T', 'r', 1, $digest, 'review', 1, StageOutcome::PASS, 'c', [], [], 'ok', observations: ['  ']);
            self::fail('An empty observation must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new StageResult('s', 'T', 'r', 1, $digest, 'review', 1, StageOutcome::PASS, 'c', [], [], 'ok', observations: array_fill(0, StageResult::MAX_OBSERVATIONS + 1, 'x'));
    }

    public function testReviewPromptAsksForObservations(): void
    {
        $this->advanceToReview();

        $review = (new ExecutionGateway($this->root))->prepareStage('ABC-123', 'review');
        self::assertStringContainsString('--observation', $review->prompt);
    }

    public function testObservationIsRefusedOutsideAPassingReviewStage(): void
    {
        ob_start();
        $exit = (new WorkflowPipelineCommand($this->root))->run([
            'submit', 'ABC-123', '--outcome', 'completed', '--observation', 'not a review',
        ]);
        ob_end_clean();

        self::assertNotSame(0, $exit);
        self::assertSame('investigate', (new ExecutionGateway($this->root))->projection('ABC-123')->currentStageId);
    }

    public function testPassingReviewObservationsPersistAndBlockNoDurableLearning(): void
    {
        $candidate = $this->advanceToReview();

        ob_start();
        $exit = (new WorkflowPipelineCommand($this->root))->run([
            'submit', 'ABC-123', '--outcome', 'pass', '--candidate', $candidate, '--context-id', 'ctx:review',
            '--summary', 'Review passed.',
            '--observation', 'Per-item facts can exceed the coverage maxItems at the reader cap.',
            '--observation', 'Guide names a bare sync-githooks command.',
        ]);
        ob_end_clean();
        self::assertSame(0, $exit);

        $state = (new ExecutionStateStore($this->root))->find('ABC-123');
        self::assertNotNull($state);
        $review = null;
        foreach ($state->history as $accepted) {
            if ($accepted->result->stageId === 'review') {
                $review = $accepted->result;
            }
        }
        self::assertNotNull($review);
        self::assertCount(2, $review->observations);

        $run = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($run);
        $observations = (new WorkflowLearningRecorder($this->root))->reviewObservations($run);
        self::assertSame([
            'Per-item facts can exceed the coverage maxItems at the reader cap.',
            'Guide names a bare sync-githooks command.',
        ], $observations);

        WorkflowLearningRecorder::assertObservationsDispositioned(RunLearningDecisionStatus::FINDINGS_RECORDED, $observations, $run->runId);
        WorkflowLearningRecorder::assertObservationsDispositioned(RunLearningDecisionStatus::FOLLOW_UP_REQUIRED, $observations, $run->runId);
        WorkflowLearningRecorder::assertObservationsDispositioned(RunLearningDecisionStatus::NO_DURABLE_LEARNING, [], $run->runId);

        try {
            WorkflowLearningRecorder::assertObservationsDispositioned(RunLearningDecisionStatus::NO_DURABLE_LEARNING, $observations, $run->runId);
            self::fail('no_durable_learning must be refused while review observations are recorded.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('2 non-blocking observation(s)', $exception->getMessage());
            self::assertStringContainsString('Guide names a bare sync-githooks command.', $exception->getMessage());
            self::assertStringContainsString('--follow-up-ref', $exception->getMessage());
        }
    }

    private function advanceToReview(): string
    {
        $gateway = new ExecutionGateway($this->root);
        $investigate = $gateway->prepareStage('ABC-123', 'investigate');
        $gateway->submitStageResult(new StageResult(
            'submission:investigate',
            $investigate->taskId,
            $investigate->runId,
            $investigate->contractRevision,
            $investigate->executionPlanDigest,
            $investigate->stageId,
            $investigate->attempt,
            StageOutcome::COMPLETED,
            $investigate->candidateRevision,
            [],
            [],
            'Investigation complete.',
        ));

        $build = $gateway->prepareStage('ABC-123', 'build');
        $gateway->submitStageResult(new StageResult(
            'submission:build',
            $build->taskId,
            $build->runId,
            $build->contractRevision,
            $build->executionPlanDigest,
            $build->stageId,
            $build->attempt,
            StageOutcome::COMPLETED,
            $build->candidateRevision,
            [],
            [],
            'Build complete.',
            'ctx:build',
        ));
        self::assertSame('review', $gateway->projection('ABC-123')->currentStageId);

        return $build->candidateRevision;
    }
}
