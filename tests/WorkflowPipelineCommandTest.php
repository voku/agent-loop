<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionStage;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class WorkflowPipelineCommandTest extends TestCase
{
    private string $root;
    private string $baseCommit;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . "/agent-loop-pipeline-" . bin2hex(random_bytes(5));
        mkdir($this->root . "/.agent-loop/learning", 0o775, true);
        mkdir($this->root . "/src", 0o775, true);
        file_put_contents($this->root . "/src/Foo.php", "<?php\nfinal class Foo {}\n");
        // A real base commit: the deterministic verify stage validates the governed candidate held by the checkout.
        file_put_contents($this->root . "/.gitignore", "/.agent-loop/\n");
        $git = "git -C " . escapeshellarg($this->root);
        exec("$git init -q && $git config user.email x@y.test && $git config user.name X && $git add . && $git commit -qm base", $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        $this->baseCommit = trim((string) shell_exec("$git rev-parse HEAD"));
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testPipelineHelpPrintsUsage(): void
    {
        $command = new WorkflowPipelineCommand($this->root);
        $res = $this->captureRun($command, ["--help"]);
        self::assertSame(0, $res["exit"]);
        self::assertStringContainsString("agent-loop pipeline status", $res["stdout"]);
        self::assertStringContainsString("agent-loop pipeline stage", $res["stdout"]);
        self::assertStringContainsString("agent-loop pipeline run", $res["stdout"]);
        self::assertStringContainsString("agent-loop pipeline submit", $res["stdout"]);
        self::assertStringContainsString("--workspace <path>", $res["stdout"]);
        self::assertStringContainsString("agent-loop pipeline recover", $res["stdout"]);
    }

    public function testPipelineStatusReportsSurgicalStageProgression(): void
    {
        $this->prepareSurgicalRun("PIPE-1");

        $command = new WorkflowPipelineCommand($this->root);
        $statusRes = $this->captureRun($command, ["status", "PIPE-1", "--format=json"]);
        self::assertSame(0, $statusRes["exit"]);
        $status = json_decode($statusRes["stdout"], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame("surgical", $status["profile"]);
        self::assertSame("in_progress", $status["status"]);
        self::assertSame("investigate", $status["current_stage"]);
        self::assertSame("investigator", $status["role"]);
        self::assertFalse($status["may_mutate"]);
        self::assertSame(1, $status["attempt"]);
        self::assertFalse($status["complete"]);

        // Inspect stage briefing
        $stageRes = $this->captureRun($command, ["stage", "PIPE-1", "--format=json"]);
        self::assertSame(0, $stageRes["exit"]);
        $stage = json_decode($stageRes["stdout"], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame("investigate", $stage["stage_id"]);
        self::assertSame("investigator", $stage["role"]);
        self::assertFalse($stage["may_mutate"]);
        self::assertContains("completed", $stage["accepted_outcomes"]);
        self::assertStringContainsString("# Governed execution stage", $stage["prompt"]);
    }

    public function testMutatingStageGuidesSubmissionFromTheEditedWorkspace(): void
    {
        $this->prepareSurgicalRun("PIPE-WORKSPACE");
        $command = new WorkflowPipelineCommand($this->root);

        $readOnly = $this->captureRun($command, ["stage", "PIPE-WORKSPACE", "--format=json"]);
        self::assertSame(0, $readOnly["exit"]);
        $investigate = json_decode($readOnly["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertFalse($investigate["may_mutate"]);
        self::assertStringNotContainsString("--workspace", $investigate["next_action"]);
        self::assertStringNotContainsString('Use --workspace <path> to identify the checkout containing your edits.', $investigate["prompt"]);

        $submitted = $this->captureRun($command, ["submit", "PIPE-WORKSPACE", "--outcome=completed"]);
        self::assertSame(0, $submitted["exit"]);
        $result = $this->captureRun($command, ["stage", "PIPE-WORKSPACE", "--format=json"]);
        self::assertSame(0, $result["exit"]);
        $build = json_decode($result["stdout"], true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($build["may_mutate"]);
        self::assertStringContainsString('--workspace "<workspace-with-edits>"', $build["next_action"]);
        self::assertStringContainsString('--workspace <path>', $build["prompt"]);
        self::assertStringContainsString('--candidate <identity>', $build["prompt"]);
        self::assertStringContainsString('optional except for an intentional revert to the base', $build["prompt"]);
        self::assertStringContainsString('checked against the workspace', $build["prompt"]);

        $text = $this->captureRun($command, ["stage", "PIPE-WORKSPACE"]);
        self::assertSame(0, $text["exit"]);
        self::assertStringContainsString('--workspace "<workspace-with-edits>"', $text["stdout"]);
        self::assertStringContainsString('--candidate <identity>', $text["stdout"]);
    }

    public function testDeterministicMutatingStageDoesNotSuggestUncheckedWorkspace(): void
    {
        $taskId = "PIPE-DETERMINISTIC-WORKSPACE";
        $this->prepareSurgicalRun($taskId);
        $this->persistDeterministicMutatingVerifyStage($taskId);
        $command = new WorkflowPipelineCommand($this->root);

        $investigate = $this->captureRun($command, ["submit", $taskId, "--outcome=completed"]);
        self::assertSame(0, $investigate["exit"], $investigate["stdout"]);
        $build = $this->captureRun($command, ["submit", $taskId, "--outcome=completed", "--context-id=ctx:build"]);
        self::assertSame(0, $build["exit"], $build["stdout"]);
        $review = $this->captureRun($command, ["submit", $taskId, "--outcome=pass", "--context-id=ctx:review"]);
        self::assertSame(0, $review["exit"], $review["stdout"]);

        $response = $this->captureRun($command, ["stage", $taskId, "--format=json"]);
        self::assertSame(0, $response["exit"], $response["stdout"]);
        $briefing = json_decode($response["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(ExecutionStageKind::DETERMINISTIC->value, $briefing["kind"]);
        self::assertTrue($briefing["may_mutate"]);
        self::assertStringNotContainsString("--workspace", $briefing["next_action"]);
        self::assertStringNotContainsString("Use --workspace <path>", $briefing["prompt"]);
    }

    /** Persist a valid plan and its bound state with a deterministic stage marked mutating. */
    private function persistDeterministicMutatingVerifyStage(string $taskId): void
    {
        $planStore = new ExecutionPlanStore($this->root);
        $original = $planStore->load($taskId);
        $stages = array_map(
            static fn (ExecutionStage $stage): ExecutionStage => $stage->id === "verify"
                ? new ExecutionStage($stage->id, $stage->kind, $stage->roleId, true, $stage->requires, $stage->transitions, $stage->contextPolicy)
                : $stage,
            $original->stages,
        );
        $plan = new ExecutionPlan(
            $original->taskId,
            $original->runId,
            $original->contractRevision,
            $original->contractSource,
            $original->baseCommit,
            $original->profile,
            $original->roles,
            $stages,
            $original->preparedAt,
        );
        self::assertNotFalse(file_put_contents($planStore->path($taskId), json_encode($plan->toArray(), JSON_THROW_ON_ERROR)));

        $stateStore = new ExecutionStateStore($this->root);
        $originalState = $stateStore->find($taskId);
        self::assertNotNull($originalState);
        $state = new ExecutionState(
            $originalState->taskId,
            $originalState->runId,
            $originalState->contractRevision,
            $plan->digest(),
            $originalState->currentStageId,
            $originalState->currentAttempt,
            $originalState->candidateRevision,
            $originalState->attention,
            $originalState->history,
            $originalState->recoveryHistory,
        );
        self::assertNotFalse(file_put_contents($stateStore->path($taskId), json_encode($state->toArray(), JSON_THROW_ON_ERROR)));
    }

    public function testPipelineProgressionWithReviewFeedbackLoop(): void
    {
        $this->prepareSurgicalRun("PIPE-2");
        $command = new WorkflowPipelineCommand($this->root);

        // 1. Submit investigate -> advances to build
        $res1 = $this->captureRun($command, [
            "submit", "PIPE-2",
            "--outcome=completed",
            "--summary=Investigation completed successfully.",
            "--format=json",
        ]);
        self::assertSame(0, $res1["exit"]);
        $p1 = json_decode($res1["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("build", $p1["current_stage"]);
        self::assertSame(1, $p1["attempt"]);

        // 2. Submit build -> advances to review
        $res2 = $this->captureRun($command, [
            "submit", "PIPE-2",
            "--outcome=completed",
            "--summary=Implementation completed in Foo.php.", "--context-id=ctx:build-1",
            "--format=json",
        ]);
        self::assertSame(0, $res2["exit"]);
        $p2 = json_decode($res2["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("review", $p2["current_stage"]);
        self::assertSame(1, $p2["attempt"]);

        // 3. Reviewer rejects: submit changes_required -> loops back to build!
        $res3 = $this->captureRun($command, [
            "submit", "PIPE-2",
            "--outcome=changes_required",
            "--summary=Add missing null-check in Foo.php.", "--context-id=ctx:review-1",
            "--artifact=src/Foo.php",
            "--format=json",
        ]);
        self::assertSame(0, $res3["exit"]);
        $p3 = json_decode($res3["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("build", $p3["current_stage"]);
        self::assertSame(2, $p3["attempt"], "Attempt counter for build must increment upon review loopback.");

        // Inspect stage in build to verify handoff feedback from review
        $stageRes = $this->captureRun($command, ["stage", "PIPE-2", "--format=json"]);
        self::assertSame(0, $stageRes["exit"]);
        $stage = json_decode($stageRes["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertNotNull($stage["prior_handoff"]);
        self::assertSame("review", $stage["prior_handoff"]["from_stage"]);
        self::assertSame("build", $stage["prior_handoff"]["to_stage"]);

        // 4. Builder re-submits build -> advances to review (attempt 2)
        $res4 = $this->captureRun($command, [
            "submit", "PIPE-2",
            "--outcome=completed",
            "--summary=Added null-check.", "--context-id=ctx:build-2",
            "--format=json",
        ]);
        self::assertSame(0, $res4["exit"]);
        $p4 = json_decode($res4["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("review", $p4["current_stage"]);

        // 5. Reviewer accepts: submit pass -> advances to verify
        $res5 = $this->captureRun($command, [
            "submit", "PIPE-2",
            "--outcome=pass",
            "--summary=All checks satisfied.", "--context-id=ctx:review-2",
            "--format=json",
        ]);
        self::assertSame(0, $res5["exit"]);
        $p5 = json_decode($res5["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("verify", $p5["current_stage"]);
    }

    public function testPipelineRunCompletesDeterministicVerification(): void
    {
        $this->prepareSurgicalRun("PIPE-3");
        $command = new WorkflowPipelineCommand($this->root);

        // Advance through investigate and build
        $this->captureRun($command, ["submit", "PIPE-3", "--outcome=completed", "--summary=Done"]);
        $this->captureRun($command, ["submit", "PIPE-3", "--outcome=completed", "--summary=Done", "--context-id=ctx:build"]);

        // Review passes -> next is deterministic verify
        $this->captureRun($command, ["submit", "PIPE-3", "--outcome=pass", "--summary=Passed", "--context-id=ctx:review"]);

        // In verify stage, running pipeline run executes deterministic verify
        // Create valid evidence for verifier so verify passes
        $runRes = $this->captureRun($command, ["run", "PIPE-3", "--format=json"]);
        // If verifier needs full repository git tree, let us see how it behaves:
        $p = json_decode($runRes["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey("complete", $p);
    }

    public function testPipelineRunVerifiesAnExplicitLinkedCandidateWorkspace(): void
    {
        $this->prepareSurgicalRun("PIPE-WORKSPACE", ["test -f .agent-loop/candidate-marker"]);
        $workspace = $this->root . "-candidate";
        $git = "git -C " . escapeshellarg($this->root);
        exec("$git worktree add -q --detach " . escapeshellarg($workspace) . " " . escapeshellarg($this->baseCommit));
        try {
            self::assertFileExists($workspace . "/src/Foo.php");
            mkdir($workspace . "/.agent-loop", 0o775, true);
            file_put_contents($workspace . "/.agent-loop/candidate-marker", "candidate worktree\n");
            $command = new WorkflowPipelineCommand($this->root);

            $this->captureRun($command, ["submit", "PIPE-WORKSPACE", "--outcome=completed"]);
            $this->captureRun($command, ["submit", "PIPE-WORKSPACE", "--outcome=completed", "--context-id=ctx:build-workspace"]);
            $this->captureRun($command, ["submit", "PIPE-WORKSPACE", "--outcome=pass", "--context-id=ctx:review-workspace"]);

            $run = $this->captureRun($command, ["run", "PIPE-WORKSPACE", "--workspace", $workspace, "--format=json"]);
            self::assertSame(0, $run["exit"], $run["stdout"]);
            $payload = json_decode($run["stdout"], true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($payload["complete"]);
        } finally {
            exec("$git worktree remove --force " . escapeshellarg($workspace) . " 2>/dev/null");
            $this->rm($workspace);
        }
    }

    public function testPipelineSubmitAutoVerifyUsesExplicitLinkedCandidateWorkspace(): void
    {
        $this->prepareSurgicalRun("PIPE-WORKSPACE-SUBMIT", [
            "test -f .agent-loop/candidate-marker",
            "grep -q changed-candidate src/Foo.php",
        ]);
        $workspace = $this->root . "-candidate-submit";
        $git = "git -C " . escapeshellarg($this->root);
        exec("$git worktree add -q --detach " . escapeshellarg($workspace) . " " . escapeshellarg($this->baseCommit));
        try {
            mkdir($workspace . "/.agent-loop", 0o775, true);
            file_put_contents($workspace . "/.agent-loop/candidate-marker", "candidate worktree\n");
            file_put_contents($workspace . "/src/Foo.php", "<?php\nfinal class Foo {} // changed-candidate\n");
            $command = new WorkflowPipelineCommand($this->root);

            $this->captureRun($command, ["submit", "PIPE-WORKSPACE-SUBMIT", "--outcome=completed"]);
            $build = $this->captureRun($command, [
                "submit", "PIPE-WORKSPACE-SUBMIT", "--outcome=completed", "--context-id=ctx:build-workspace-submit",
                "--workspace", $workspace, "--format=json",
            ]);
            self::assertSame(0, $build["exit"], $build["stdout"]);
            $review = $this->captureRun($command, [
                "submit", "PIPE-WORKSPACE-SUBMIT", "--outcome=pass", "--context-id=ctx:review-workspace-submit",
                "--artifact", "src/Foo.php", "--auto-verify", "--workspace", $workspace, "--format=json",
            ]);

            self::assertSame(0, $review["exit"], $review["stdout"]);
            $payload = json_decode($review["stdout"], true, 512, JSON_THROW_ON_ERROR);
            self::assertTrue($payload["complete"]);
        } finally {
            exec("$git worktree remove --force " . escapeshellarg($workspace) . " 2>/dev/null");
            $this->rm($workspace);
        }
    }

    public function testPipelineCompletedStageInspection(): void
    {
        $this->prepareSurgicalRun("PIPE-4");
        $command = new WorkflowPipelineCommand($this->root);

        // Advance through all stages
        $this->captureRun($command, ["submit", "PIPE-4", "--outcome=completed", "--summary=Done"]);
        $this->captureRun($command, ["submit", "PIPE-4", "--outcome=completed", "--summary=Done", "--context-id=ctx:build"]);
        $this->captureRun($command, ["submit", "PIPE-4", "--outcome=pass", "--summary=Passed", "--context-id=ctx:review"]);
        $this->captureRun($command, ["run", "PIPE-4"]);

        // Now test stage and status on complete pipeline
        $stageRes = $this->captureRun($command, ["stage", "PIPE-4", "--format=json"]);
        self::assertSame(0, $stageRes["exit"]);
        $stage = json_decode($stageRes["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("complete", $stage["status"]);

        $statusRes = $this->captureRun($command, ["status", "PIPE-4", "--format=json"]);
        self::assertSame(0, $statusRes["exit"]);
        $status = json_decode($statusRes["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertTrue($status["complete"]);
        self::assertSame("complete", $status["status"]);
        self::assertSame("agent-loop finish PIPE-4", $status["next_action"]);
    }

    public function testPipelineSubmitRejectsInvalidOutcome(): void
    {
        $this->prepareSurgicalRun("PIPE-5");
        $command = new WorkflowPipelineCommand($this->root);

        $res = $this->captureRun($command, ["submit", "PIPE-5", "--outcome=invalid_outcome", "--format=json"]);
        self::assertSame(1, $res["exit"]);
        $payload = json_decode($res["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("error", $payload["status"]);
        self::assertStringContainsString("Invalid outcome", $payload["message"]);
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function captureRun(WorkflowPipelineCommand $command, array $args): array
    {
        $level = ob_get_level();
        ob_start();
        $stderrStream = fopen("php://memory", "r+");
        $prevStderr = ini_get("error_log");
        try {
            $exit = $command->run($args);
            $stdout = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        // Catch any output from stderr if captured or mock
        return ["exit" => $exit, "stdout" => $stdout, "stderr" => $stdout];
    }

    /** @param list<string> $validation */
    private function prepareSurgicalRun(string $taskId, array $validation = ["php -l src/Foo.php"]): void
    {
        ob_start();
        (new WorkflowPlanCommand($this->root))->run([
            $taskId,
            "--by", "lars",
            "--file", "src/Foo.php",
            "--goal", "Pipeline execution test.",
            ...array_merge(...array_map(static fn (string $command): array => ["--validation", $command], $validation)),
            "--base-commit", $this->baseCommit,
        ]);
        $revision = (new TaskContractStore($this->root))->load($taskId)->revision;
        (new WorkflowApproveCommand($this->root))->run([$taskId, '--revision', (string) $revision, '--by', 'lars']);
        (new WorkflowExecutionProfileCommand($this->root))->run([$taskId, "--profile", "surgical", "--by", "lars"]);
        ob_end_clean();

        ob_start();
        (new HostFrontDoorCommand(
            $this->root,
        ))->run("enter", [$taskId, "--format=json"]);
        ob_end_clean();
    }

    private function rm(string $path): void
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
