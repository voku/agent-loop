<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/**
 * The deterministic verify stage must validate the candidate the Run accepted, not the project checkout.
 * Regression for #799: a candidate that failed its declared validation used to complete the plan with a PASS.
 */
final class ExecutionVerifyCandidateValidationTest extends TestCase
{
    private string $root;
    private string $workspace;
    private string $base;

    protected function setUp(): void
    {
        $id = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/agent-loop-verify-' . $id;
        $this->workspace = sys_get_temp_dir() . '/agent-loop-verify-ws-' . $id;
    }

    protected function tearDown(): void
    {
        exec('git -C ' . escapeshellarg($this->root) . ' worktree remove --force ' . escapeshellarg($this->workspace) . ' 2>/dev/null');
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->workspace));
    }

    public function testCandidateThatFailsItsDeclaredValidationDoesNotPassVerify(): void
    {
        $this->reachVerifyWith("<?php\nfinal class Foo { this is not php }\n");

        $projection = (new ExecutionGateway($this->root))->runDeterministicStage('VER-1', 'verify', $this->workspace);

        self::assertFalse($projection->complete());
        self::assertNotNull($projection->attention);
        self::assertStringContainsString('[FAIL] php -l src/Foo.php', $projection->attention->message);
    }

    public function testCandidateThatPassesItsDeclaredValidationCompletesThePlan(): void
    {
        $this->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n");

        $projection = (new ExecutionGateway($this->root))->runDeterministicStage('VER-1', 'verify', $this->workspace);

        self::assertTrue($projection->complete());
        self::assertNull($projection->attention);
    }

    public function testWorkspaceThatDoesNotHoldTheGovernedCandidateIsRefused(): void
    {
        $this->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');
        // The project checkout still holds the base, not the candidate the build stage produced.
        (new ExecutionGateway($this->root))->runDeterministicStage('VER-1', 'verify');
    }

    public function testValidationThatChangesTheCandidateIsRefused(): void
    {
        $this->reachVerifyWith("<?php\nfinal class Foo { public const int X = 1; }\n", 'php -r "file_put_contents(\'side-effect.txt\', \'x\');"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed the governed candidate');
        (new ExecutionGateway($this->root))->runDeterministicStage('VER-1', 'verify', $this->workspace);
    }

    private function reachVerifyWith(string $source, string $validation = 'php -l src/Foo.php'): void
    {
        $this->prepare($validation);

        $gateway = new ExecutionGateway($this->root);
        $investigate = $gateway->prepareStage('VER-1', 'investigate');
        $gateway->submitStageResult($this->stageResult($investigate->stageId, $investigate->attempt, StageOutcome::COMPLETED, $investigate->candidateRevision, $investigate->runId, $investigate->executionPlanDigest, $investigate->contractRevision));

        file_put_contents($this->workspace . '/src/Foo.php', $source);
        $candidate = 'git-tree-v1:' . $this->base . ':' . $this->workspaceTree();
        $build = $gateway->prepareStage('VER-1', 'build');
        $gateway->recordStageCandidate(new StageCandidateObservation(
            $build->taskId,
            $build->runId,
            $build->contractRevision,
            $build->executionPlanDigest,
            $build->stageId,
            $build->attempt,
            $build->candidateRevision,
            $candidate,
        ));
        $gateway->submitStageResult($this->stageResult($build->stageId, $build->attempt, StageOutcome::COMPLETED, $candidate, $build->runId, $build->executionPlanDigest, $build->contractRevision, 'ctx:build'));

        $review = $gateway->prepareStage('VER-1', 'review');
        $gateway->submitStageResult($this->stageResult($review->stageId, $review->attempt, StageOutcome::PASS, $candidate, $review->runId, $review->executionPlanDigest, $review->contractRevision, 'ctx:review'));
    }

    private function prepare(string $validation): void
    {
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $this->git($this->root, 'init', '-q');
        $this->git($this->root, 'config', 'user.email', 'x@y.test');
        $this->git($this->root, 'config', 'user.name', 'X');
        $this->git($this->root, 'add', '.');
        $this->git($this->root, 'commit', '-qm', 'base');
        $this->base = $this->git($this->root, 'rev-parse', 'HEAD');
        $this->git($this->root, 'worktree', 'add', '-q', '--detach', $this->workspace, $this->base);

        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'VER-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', 'Change Foo.',
            '--validation', $validation, '--base-commit', $this->base,
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['VER-1', '--by', 'approver']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run(['VER-1', '--profile', 'surgical', '--by', 'approver']));
        self::assertSame(0, (new HostFrontDoorCommand($this->root))->run('enter', ['VER-1', '--format=json']));
        ob_end_clean();
    }

    private function stageResult(
        string $stageId,
        int $attempt,
        StageOutcome $outcome,
        string $candidate,
        string $runId,
        string $planDigest,
        int $contractRevision,
        ?string $contextId = null,
    ): StageResult {
        return new StageResult(
            'submission:' . $stageId,
            'VER-1',
            $runId,
            $contractRevision,
            $planDigest,
            $stageId,
            $attempt,
            $outcome,
            $candidate,
            [],
            [],
            'fixture ' . $stageId,
            $contextId,
        );
    }

    private function workspaceTree(): string
    {
        $index = sys_get_temp_dir() . '/agent-loop-verify-test-index-' . bin2hex(random_bytes(4));
        $env = 'GIT_INDEX_FILE=' . escapeshellarg($index) . ' ';
        $cd = 'cd ' . escapeshellarg($this->workspace) . ' && ';
        exec($cd . $env . 'git read-tree ' . escapeshellarg($this->base) . ' && ' . $env . 'git add -A -- . && ' . $env . 'git write-tree', $out, $exit);
        @unlink($index);
        self::assertSame(0, $exit);

        return trim((string) end($out));
    }

    private function git(string $directory, string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
