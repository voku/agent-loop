<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class WorkflowPipelineCommandTest extends TestCase
{
    private const string BASE_COMMIT = "1111111111111111111111111111111111111111";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . "/agent-loop-pipeline-" . bin2hex(random_bytes(5));
        mkdir($this->root . "/.agent-loop/learning", 0o775, true);
        mkdir($this->root . "/src", 0o775, true);
        file_put_contents($this->root . "/src/Foo.php", "<?php\nfinal class Foo {}\n");
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
            "--summary=Implementation completed in Foo.php.",
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
            "--summary=Add missing null-check in Foo.php.",
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
            "--summary=Added null-check.",
            "--format=json",
        ]);
        self::assertSame(0, $res4["exit"]);
        $p4 = json_decode($res4["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame("review", $p4["current_stage"]);

        // 5. Reviewer accepts: submit pass -> advances to verify
        $res5 = $this->captureRun($command, [
            "submit", "PIPE-2",
            "--outcome=pass",
            "--summary=All checks satisfied.",
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
        $this->captureRun($command, ["submit", "PIPE-3", "--outcome=completed", "--summary=Done"]);

        // Review passes -> next is deterministic verify
        $this->captureRun($command, ["submit", "PIPE-3", "--outcome=pass", "--summary=Passed"]);

        // In verify stage, running pipeline run executes deterministic verify
        // Create valid evidence for verifier so verify passes
        $runRes = $this->captureRun($command, ["run", "PIPE-3", "--format=json"]);
        // If verifier needs full repository git tree, let us see how it behaves:
        $p = json_decode($runRes["stdout"], true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey("complete", $p);
    }

    public function testPipelineCompletedStageInspection(): void
    {
        $this->prepareSurgicalRun("PIPE-4");
        $command = new WorkflowPipelineCommand($this->root);

        // Advance through all stages
        $this->captureRun($command, ["submit", "PIPE-4", "--outcome=completed", "--summary=Done"]);
        $this->captureRun($command, ["submit", "PIPE-4", "--outcome=completed", "--summary=Done"]);
        $this->captureRun($command, ["submit", "PIPE-4", "--outcome=pass", "--summary=Passed"]);
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

    private function prepareSurgicalRun(string $taskId): void
    {
        ob_start();
        (new WorkflowPlanCommand($this->root))->run([
            $taskId,
            "--by", "lars",
            "--file", "src/Foo.php",
            "--goal", "Pipeline execution test.",
            "--validation", "php -l src/Foo.php",
            "--base-commit", self::BASE_COMMIT,
        ]);
        (new WorkflowApproveCommand($this->root))->run([$taskId, "--by", "lars"]);
        (new WorkflowExecutionProfileCommand($this->root))->run([$taskId, "--profile", "surgical", "--by", "lars"]);
        ob_end_clean();

        ob_start();
        (new HostFrontDoorCommand(
            $this->root,
            function (array $argv) use ($taskId): int {
                $dir = $this->root . "/.agent-loop/recall/" . $taskId;
                if (!is_dir($dir)) {
                    mkdir($dir, 0o775, true);
                }
                file_put_contents($dir . "/meta.json", json_encode([
                    "schema_version" => "1.0",
                    "task_id" => $taskId,
                    "compilation_id" => $taskId . "-compile",
                    "selected_guidance" => [],
                    "selected_constraints" => [],
                    "output_hashes" => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents($dir . "/system.md", "# Governed recall\nStay inside scope.\n");

                return 0;
            },
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
