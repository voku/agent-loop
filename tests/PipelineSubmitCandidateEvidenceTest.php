<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use stdClass;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/** `pipeline submit` must observe the workspace candidate itself and fail closed on a mismatched claim (#851). */
final class PipelineSubmitCandidateEvidenceTest extends TestCase
{
    private string $root;
    private string $workspace;
    private string $base;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(5));
        $this->root = sys_get_temp_dir() . '/agent-loop-submit-' . $suffix;
        $this->workspace = sys_get_temp_dir() . '/agent-loop-submit-ws-' . $suffix;
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n/vendor/\n");
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
                    'SUB-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', 'Change Foo.',
                    '--validation', 'php -l src/Foo.php', '--base-commit', $this->base,
                ]),
                (new WorkflowApproveCommand($this->root))->run(['SUB-1', '--revision', '1', '--by', 'approver']),
                (new WorkflowExecutionProfileCommand($this->root))->run(['SUB-1', '--profile', 'surgical', '--by', 'approver']),
                (new HostFrontDoorCommand($this->root))->run('enter', ['SUB-1', '--format=json']),
            ];
        } finally {
            $output = (string) ob_get_clean();
        }
        self::assertSame([0, 0, 0, 0], $exits, $output);

        [$exit] = $this->submit(['--outcome', 'completed']);
        self::assertSame(0, $exit);
        self::assertSame('build', $this->projection()->currentStageId);
    }

    protected function tearDown(): void
    {
        exec('git -C ' . escapeshellarg($this->root) . ' worktree remove --force ' . escapeshellarg($this->workspace) . ' 2>/dev/null');
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->workspace));
    }

    public function testChangedWorkspaceWithObservedCandidateIsRecordedAndAdvances(): void
    {
        $this->changeWorkspace();
        $candidate = $this->observedCandidate();

        [$exit] = $this->submit(['--outcome', 'completed', '--candidate', $candidate, '--context-id', 'ctx:build']);

        self::assertSame(0, $exit);
        $projection = $this->projection();
        self::assertSame('review', $projection->currentStageId);
        self::assertSame($candidate, $projection->candidateRevision);
        self::assertNotSame([], $this->evidenceFiles(), 'candidate evidence must be persisted');
    }

    public function testOmittedCandidateUsesTheObservedWorkspaceCandidate(): void
    {
        $this->changeWorkspace();
        $candidate = $this->observedCandidate();

        [$exit] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build']);

        self::assertSame(0, $exit);
        self::assertSame($candidate, $this->projection()->candidateRevision);
    }

    public function testClaimedCandidateThatTheWorkspaceDoesNotHoldIsRefusedWithoutStateChange(): void
    {
        $this->changeWorkspace();
        $forged = 'git-tree-v1:' . $this->base . ':' . str_repeat('a', 40);

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--candidate', $forged, '--context-id', 'ctx:build']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', (string) ($payload->message ?? ''));
        $projection = $this->projection();
        self::assertSame('build', $projection->currentStageId);
        self::assertSame($this->base, $projection->candidateRevision);
        self::assertSame([], $this->evidenceFiles(), 'a refused claim must not persist evidence');
    }

    public function testBaseCandidateClaimIsRefusedWhenTheWorkspaceChanged(): void
    {
        $this->changeWorkspace();

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--candidate', $this->base, '--context-id', 'ctx:build']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', (string) ($payload->message ?? ''));
        self::assertSame('build', $this->projection()->currentStageId);
    }

    public function testArtifactCitationOnAChangedBuildBindsToTheObservedCandidate(): void
    {
        $this->changeWorkspace();
        $candidate = $this->observedCandidate();

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--artifact', 'src/Foo.php', '--context-id', 'ctx:build']);

        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame($candidate, $this->projection()->candidateRevision);
    }

    public function testRetryAfterALaterRefusalReusesTheRecordedCandidateEvidence(): void
    {
        $this->changeWorkspace();
        $candidate = $this->observedCandidate();

        [$firstExit] = $this->submit(['--outcome', 'completed']);
        self::assertNotSame(0, $firstExit, 'a build without --context-id must be refused');
        self::assertSame('build', $this->projection()->currentStageId);

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build']);

        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame($candidate, $this->projection()->candidateRevision);
    }

    public function testBlockedCanBeReportedFromAWorkspaceWhoseHeadMoved(): void
    {
        $this->changeWorkspace();
        $this->git($this->workspace, 'config', 'user.email', 'x@y.test');
        $this->git($this->workspace, 'config', 'user.name', 'X');
        $this->git($this->workspace, 'commit', '-aqm', 'builder committed');

        [$exit, $payload] = $this->submit(['--outcome', 'blocked', '--summary', 'cannot proceed', '--context-id', 'ctx:build']);

        self::assertSame('runner_failed', $payload->attention->kind ?? null, (string) json_encode($payload));
        self::assertStringNotContainsString('STALE_CANDIDATE', (string) json_encode($payload));
        self::assertSame($this->base, $this->projection()->candidateRevision);
    }

    public function testCompletingFromAWorkspaceWhoseHeadMovedIsRefused(): void
    {
        $this->changeWorkspace();
        $this->git($this->workspace, 'config', 'user.email', 'x@y.test');
        $this->git($this->workspace, 'config', 'user.name', 'X');
        $this->git($this->workspace, 'commit', '-aqm', 'builder committed');

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('STALE_CANDIDATE', (string) ($payload->message ?? ''));
        self::assertSame('build', $this->projection()->currentStageId);
    }

    public function testLaterAttemptDoesNotSilentlyRevertToTheBaseWhenTheWorkspaceIsOmitted(): void
    {
        $this->changeWorkspace();
        $first = $this->observedCandidate();
        [$exit] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build']);
        self::assertSame(0, $exit);
        [$exit, $payload] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--context-id', 'ctx:review']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('build', $this->projection()->currentStageId);
        self::assertSame($first, $this->projection()->candidateRevision);

        // The builder forgets --workspace, so the clean project root is observed instead of the candidate worktree.
        $level = ob_get_level();
        ob_start();
        try {
            $exit = (new WorkflowPipelineCommand($this->root))->run(['submit', 'SUB-1', '--outcome', 'completed', '--context-id', 'ctx:build2', '--format=json']);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('CANDIDATE_MISMATCH', $output);
        self::assertSame('build', $this->projection()->currentStageId);
        self::assertSame($first, $this->projection()->candidateRevision);
    }

    public function testLaterAttemptAcceptsFurtherEditsFromTheCandidateWorktree(): void
    {
        $this->changeWorkspace();
        $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build']);
        $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--context-id', 'ctx:review']);
        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 2; }\n");
        $second = $this->observedCandidate();

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build2']);

        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame($second, $this->projection()->candidateRevision);
    }

    public function testLaterAttemptAcceptsAnExplicitRevertToTheGovernedBase(): void
    {
        $this->changeWorkspace();
        $first = $this->observedCandidate();
        [$exit] = $this->submit(['--outcome', 'completed', '--candidate', $first, '--context-id', 'ctx:build']);
        self::assertSame(0, $exit);
        [$exit, $payload] = $this->submit(['--outcome', 'changes_required', '--artifact', 'src/Foo.php', '--context-id', 'ctx:review']);
        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('build', $this->projection()->currentStageId);
        self::assertSame($first, $this->projection()->candidateRevision);
        $bundle = $this->buildBundle();
        self::assertSame(2, $bundle->attempt);
        $priorEvidence = $this->evidenceFiles();

        $this->git($this->workspace, 'reset', '--hard', $this->base);
        $revert = 'git-tree-v1:' . $this->base . ':' . $this->git($this->workspace, 'rev-parse', $this->base . '^{tree}');
        self::assertNotSame($first, $revert);
        self::assertSame($revert, $this->observedCandidate());

        [$exit, $payload] = $this->submit(['--outcome', 'completed', '--candidate', $revert, '--context-id', 'ctx:build2']);

        self::assertSame(0, $exit, (string) json_encode($payload));
        self::assertSame('review', $this->projection()->currentStageId);
        self::assertSame($revert, $this->projection()->candidateRevision);
        $newEvidence = array_values(array_diff($this->evidenceFiles(), $priorEvidence));
        self::assertCount(1, $newEvidence, 'the revert must create candidate evidence for the later build attempt');
        $evidence = json_decode((string) file_get_contents($newEvidence[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($evidence);
        self::assertSame($bundle->runId, $evidence['run_id']);
        self::assertSame($bundle->attempt, $evidence['attempt']);
        self::assertSame('candidate', $evidence['evidence_kind']);
        self::assertSame($revert, $evidence['candidate_revision']);
        self::assertSame($first, $evidence['source_reference']);
    }

    public function testPersistedPlanWithoutBaseRefusesCandidateSubmission(): void
    {
        $planStore = new ExecutionPlanStore($this->root);
        $original = $planStore->load('SUB-1');
        $plan = new ExecutionPlan(
            $original->taskId,
            $original->runId,
            $original->contractRevision,
            $original->contractSource,
            null,
            $original->profile,
            $original->roles,
            $original->stages,
            $original->preparedAt,
        );
        self::assertNotFalse(file_put_contents($planStore->path('SUB-1'), json_encode($plan->toArray(), JSON_THROW_ON_ERROR)));
        $stateStore = new ExecutionStateStore($this->root);
        $state = $stateStore->find('SUB-1');
        self::assertNotNull($state);
        $payload = $state->toArray();
        $payload['execution_plan_digest'] = $plan->digest();
        self::assertNotFalse(file_put_contents($stateStore->path('SUB-1'), json_encode($payload, JSON_THROW_ON_ERROR)));
        $evidence = $this->evidenceFiles();

        [$exit, $response] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build']);

        self::assertNotSame(0, $exit);
        self::assertStringContainsString('STALE_CANDIDATE: observing a candidate requires an exact governed base commit.', (string) ($response->message ?? ''));
        self::assertSame('build', $this->projection()->currentStageId);
        self::assertSame($state->candidateRevision, $this->projection()->candidateRevision);
        self::assertSame($evidence, $this->evidenceFiles(), 'a refused submission must not persist candidate evidence');
    }

    public function testUnchangedWorkspaceKeepsTheStageCandidate(): void
    {
        [$exit] = $this->submit(['--outcome', 'completed', '--context-id', 'ctx:build']);

        self::assertSame(0, $exit);
        self::assertSame($this->base, $this->projection()->candidateRevision);
    }

    public function testSharedCheckoutReportsChangesOutsideTheApprovedScopeAsData(): void
    {
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
        file_put_contents($this->root . '/src/Other.php', "<?php\nfinal class Other {}\n");

        $observed = (new ExecutionGateway($this->root))->observeStageCandidate($this->buildBundle(), null, $this->root);

        self::assertTrue($observed->changesOutsideScope);
        self::assertSame($this->scopedCandidate(), $observed->candidateRevision, 'the foreign file must not enter the candidate');
    }

    public function testSharedCheckoutWithoutForeignFilesReportsNothingOutsideTheScope(): void
    {
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");

        $observed = (new ExecutionGateway($this->root))->observeStageCandidate($this->buildBundle(), null, $this->root);

        self::assertFalse($observed->changesOutsideScope);
        self::assertSame($this->scopedCandidate(), $observed->candidateRevision);
    }

    public function testCandidateWorktreeKeepsTheWholeTreeIdentityAndReportsNoScopeNotice(): void
    {
        $this->changeWorkspace();
        file_put_contents($this->workspace . '/src/Other.php', "<?php\nfinal class Other {}\n");

        $observed = (new ExecutionGateway($this->root))->observeStageCandidate($this->buildBundle(), null, $this->workspace);

        self::assertFalse($observed->changesOutsideScope);
        self::assertSame($this->observedCandidate(), $observed->candidateRevision);
    }

    public function testSubmitPrintsTheScopeNoticeOnStderrWhenTheSharedCheckoutHoldsOtherWork(): void
    {
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
        file_put_contents($this->root . '/src/Other.php', "<?php\nfinal class Other {}\n");

        $result = $this->runCli(['pipeline', 'submit', 'SUB-1', '--outcome', 'completed', '--context-id', 'ctx:build', '--workspace', $this->root, '--format=json']);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringContainsString('holds changes outside the approved scope', $result['stderr']);
    }

    public function testSubmitPrintsNoScopeNoticeWhenNothingOutsideTheScopeChanged(): void
    {
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");

        $result = $this->runCli(['pipeline', 'submit', 'SUB-1', '--outcome', 'completed', '--context-id', 'ctx:build', '--workspace', $this->root, '--format=json']);

        self::assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);
        self::assertStringNotContainsString('outside the approved scope', $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runCli(array $arguments): array
    {
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', ...$arguments],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function buildBundle(): \voku\AgentLoop\Execution\StageExecutionBundle
    {
        return (new ExecutionGateway($this->root))->prepareStage('SUB-1', 'build');
    }

    /** The base overlaid with only the approved scope path, computed independently of Loop. */
    private function scopedCandidate(): string
    {
        $index = sys_get_temp_dir() . '/agent-loop-submit-scope-index-' . bin2hex(random_bytes(4));
        $env = 'GIT_INDEX_FILE=' . escapeshellarg($index) . ' ';
        exec(
            'cd ' . escapeshellarg($this->root) . ' && ' . $env . 'git read-tree ' . escapeshellarg($this->base)
            . ' && ' . $env . 'git add -A -- src/Foo.php && ' . $env . 'git write-tree',
            $output,
            $exit,
        );
        if (is_file($index)) {
            unlink($index);
        }
        self::assertSame(0, $exit);

        return 'git-tree-v1:' . $this->base . ':' . trim((string) end($output));
    }

    /**
     * @param list<string> $arguments
     * @return array{int, stdClass}
     */
    private function submit(array $arguments): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $this->runSubmit($arguments);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $decoded = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
        self::assertInstanceOf(stdClass::class, $decoded);

        return [$exit, $decoded];
    }

    /** @param list<string> $arguments */
    private function runSubmit(array $arguments): int
    {
        return (new WorkflowPipelineCommand($this->root))->run([
            'submit', 'SUB-1', ...$arguments, '--workspace', $this->workspace, '--format=json',
        ]);
    }

    private function projection(): \voku\AgentLoop\Execution\ExecutionProjection
    {
        return (new ExecutionGateway($this->root))->projection('SUB-1');
    }

    /** @return list<string> */
    private function evidenceFiles(): array
    {
        $files = glob($this->root . '/.agent-loop/runs/SUB-1/execution-evidence/*') ?: [];

        return array_values(array_filter($files, 'is_file'));
    }

    private function changeWorkspace(): void
    {
        file_put_contents($this->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public int \$x = 1; }\n");
    }

    /** Computed independently of Loop with a private index, the way an execution consumer would. */
    private function observedCandidate(): string
    {
        $index = sys_get_temp_dir() . '/agent-loop-submit-index-' . bin2hex(random_bytes(4));
        $env = 'GIT_INDEX_FILE=' . escapeshellarg($index) . ' ';
        exec(
            'cd ' . escapeshellarg($this->workspace) . ' && ' . $env . 'git read-tree ' . escapeshellarg($this->base)
            . ' && ' . $env . 'git add -A -- . && ' . $env . 'git write-tree',
            $output,
            $exit,
        );
        if (is_file($index)) {
            unlink($index);
        }
        self::assertSame(0, $exit);

        return 'git-tree-v1:' . $this->base . ':' . trim((string) end($output));
    }

    private function git(string $directory, string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($directory) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
