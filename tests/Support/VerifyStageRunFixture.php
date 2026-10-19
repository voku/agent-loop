<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Support;

use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageExecutionBundle;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/**
 * Test-only governed surgical Run that reaches the deterministic verify stage with a real candidate.
 *
 * The project root is a real Git repository; the candidate lives in a detached worktree, the way an execution
 * consumer such as Runner holds it, and its `git-tree-v1` identity is computed independently of Loop.
 */
final class VerifyStageRunFixture
{
    public readonly string $root;
    public readonly string $workspace;
    private string $base = '';

    public function __construct()
    {
        $suffix = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/agent-loop-verify-' . $suffix;
        $this->workspace = sys_get_temp_dir() . '/agent-loop-verify-ws-' . $suffix;
    }

    public function remove(): void
    {
        exec('git -C ' . escapeshellarg($this->root) . ' worktree remove --force ' . escapeshellarg($this->workspace) . ' 2>/dev/null');
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->workspace));
    }

    /** Plans, approves and enters task VER-1, then submits investigate, a build that writes `$source`, and a passing review. */
    public function reachVerifyWith(string $source, string $validation = 'php -l src/Foo.php'): void
    {
        $this->enter($validation);

        $gateway = new ExecutionGateway($this->root);
        $investigate = $gateway->prepareStage('VER-1', 'investigate');
        $this->submit($gateway, $investigate, StageOutcome::COMPLETED, $investigate->candidateRevision);

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
        $this->submit($gateway, $build, StageOutcome::COMPLETED, $candidate, 'ctx:build');

        $this->submit($gateway, $gateway->prepareStage('VER-1', 'review'), StageOutcome::PASS, $candidate, 'ctx:review');
    }

    public function git(string $directory, string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        if ($exit !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed: ' . implode("\n", $output));
        }

        return trim(implode("\n", $output));
    }

    private function enter(string $validation): void
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
        try {
            $exits = [
                (new WorkflowPlanCommand($this->root))->run([
                    'VER-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', 'Change Foo.',
                    '--validation', $validation, '--base-commit', $this->base,
                ]),
                (new WorkflowApproveCommand($this->root))->run(['VER-1', '--by', 'approver']),
                (new WorkflowExecutionProfileCommand($this->root))->run(['VER-1', '--profile', 'surgical', '--by', 'approver']),
                (new HostFrontDoorCommand($this->root))->run('enter', ['VER-1', '--format=json']),
            ];
        } finally {
            $output = (string) ob_get_clean();
        }
        if ($exits !== [0, 0, 0, 0]) {
            throw new RuntimeException('Unable to prepare the governed verify fixture: ' . $output);
        }
    }

    private function submit(ExecutionGateway $gateway, StageExecutionBundle $bundle, StageOutcome $outcome, string $candidate, ?string $contextId = null): void
    {
        $gateway->submitStageResult(new StageResult(
            'submission:' . $bundle->stageId,
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $outcome,
            $candidate,
            [],
            [],
            'fixture ' . $bundle->stageId,
            $contextId,
        ));
    }

    private function workspaceTree(): string
    {
        $index = sys_get_temp_dir() . '/agent-loop-verify-test-index-' . bin2hex(random_bytes(4));
        $env = 'GIT_INDEX_FILE=' . escapeshellarg($index) . ' ';
        $inWorkspace = 'cd ' . escapeshellarg($this->workspace) . ' && ';
        exec($inWorkspace . $env . 'git read-tree ' . escapeshellarg($this->base) . ' && ' . $env . 'git add -A -- . && ' . $env . 'git write-tree', $output, $exit);
        if (is_file($index)) {
            unlink($index);
        }
        if ($exit !== 0) {
            throw new RuntimeException('Unable to compute the fixture candidate tree.');
        }

        return trim((string) end($output));
    }
}
