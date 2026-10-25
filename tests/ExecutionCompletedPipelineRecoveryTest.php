<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfile;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionStageResultAuthority;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\CompletedPipelineRecovery;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Tests\Support\VerifyStageRunFixture;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowCloseReadinessInspector;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentSession\SessionStore;

final class ExecutionCompletedPipelineRecoveryTest extends TestCase
{
    private VerifyStageRunFixture $run;

    protected function setUp(): void
    {
        $this->run = new VerifyStageRunFixture();
        $this->run->reachVerifyWith("<?php\nfinal class Foo { public const int VALUE = 1; }\n");
    }

    protected function tearDown(): void
    {
        $this->run->remove();
    }

    public function testChangedCandidateRecoversAtBuildAndKeepsPriorHistoryImmutable(): void
    {
        [$gateway, $before, $build, $review] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $indexBefore = file_get_contents($this->run->root . '/.git/index');
        $candidateB = $this->currentCandidateRevision();
        $indexAfterObservation = file_get_contents($this->run->root . '/.git/index');
        self::assertSame($indexBefore, $indexAfterObservation, 'Candidate observation must leave the owner Git index untouched.');
        $sourceBeforeRecovery = file_get_contents($this->run->root . '/src/Foo.php');

        [$exit, $payload] = $this->recover($candidateB);

        self::assertSame(0, $exit, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertFalse($payload['complete']);
        self::assertSame('build', $payload['current_stage']);
        self::assertSame(2, $payload['attempt']);

        $after = $this->state();
        self::assertSame($before->toArray()['history'], $after->toArray()['history']);
        self::assertCount(1, $after->recoveryHistory);
        self::assertSame($candidateA, $after->recoveryHistory[0]['previous_candidate_revision']);
        self::assertSame($candidateB, $after->recoveryHistory[0]['observed_candidate_revision']);
        self::assertSame(['src/Foo.php'], $after->recoveryHistory[0]['changed_files']);
        self::assertSame($sourceBeforeRecovery, file_get_contents($this->run->root . '/src/Foo.php'));
        $this->assertCloseReadinessWaitsForRecoveredPipeline();

        $buildBundle = $gateway->prepareStage('VER-1', 'build');
        self::assertSame(2, $buildBundle->attempt);
        try {
            $gateway->submitStageResult(new StageResult(
                'recovery-build-old-candidate:VER-1:2',
                $buildBundle->taskId,
                $buildBundle->runId,
                $buildBundle->contractRevision,
                $buildBundle->executionPlanDigest,
                $buildBundle->stageId,
                $buildBundle->attempt,
                StageOutcome::COMPLETED,
                $candidateA,
                [],
                [],
                'Attempt to accept the prior candidate after recovery.',
                'ctx:build-recovery-old-candidate',
            ));
            self::fail('Recovered build must not silently accept the prior candidate.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('recovered build must register a changed candidate', $exception->getMessage());
        }
        self::assertSame('build', $gateway->projection('VER-1')->currentStageId);
        $gateway->recordStageCandidate(new StageCandidateObservation(
            $buildBundle->taskId,
            $buildBundle->runId,
            $buildBundle->contractRevision,
            $buildBundle->executionPlanDigest,
            $buildBundle->stageId,
            $buildBundle->attempt,
            $candidateA,
            $candidateB,
        ));
        $gateway->submitStageResult(new StageResult(
            'recovery-build:VER-1:2',
            $buildBundle->taskId,
            $buildBundle->runId,
            $buildBundle->contractRevision,
            $buildBundle->executionPlanDigest,
            $buildBundle->stageId,
            $buildBundle->attempt,
            StageOutcome::COMPLETED,
            $candidateB,
            [],
            [],
            'Accepted the current implementation as the recovered build candidate.',
            'ctx:build-recovery-2',
        ));

        self::assertSame('review', $gateway->projection('VER-1')->currentStageId);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_STAGE_RESULT');
        $gateway->submitStageResult($review);
    }

    public function testModeOnlyRecoveryOverridesHistoricalVerificationReceipt(): void
    {
        $this->completeRun();
        $this->copyCandidateAIntoProjectRoot();
        $contract = (new TaskContractStore($this->run->root))->load('VER-1');
        $run = (new GovernedRunStore($this->run->root))->findForContract($contract);
        self::assertNotNull($run);
        $session = (new SessionStore())->activeForTask((new ProjectLayout($this->run->root))->sessionsRoot(), 'VER-1');
        self::assertNotNull($session);
        $snapshot = ImplementationSnapshot::capture($this->run->root, $contract)->digest;
        $receipts = new \voku\AgentLoop\Run\RunVerificationReceiptStore($this->run->root);
        $receipts->record($run, $contract, $session, $snapshot, 'satisfied', array_map(
            static fn (string $command): array => ['command' => $command, 'status' => 'passed', 'exit_code' => 0, 'executed_at' => '2026-10-25T00:00:00+00:00', 'recorded_by' => 'fixture', 'duration_ms' => 1],
            $contract->validation,
        ));
        $receiptBefore = file_get_contents($receipts->path('VER-1'));
        self::assertTrue(chmod($this->run->root . '/src/Foo.php', 0o755));
        self::assertSame($snapshot, ImplementationSnapshot::capture($this->run->root, $contract)->digest);
        [$exit, $payload] = $this->recover($this->currentCandidateRevision());
        self::assertSame(0, $exit, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertSame('build', $payload['current_stage']);
        $manifest = (new \voku\AgentLoop\Run\RunManifestProjector($this->run->root))->project('VER-1');
        self::assertSame('blocked', $manifest->references['verification']['state']);
        self::assertSame('execution_review', $manifest->references['verification']['gate']);
        self::assertSame('agent-loop pipeline status VER-1 --format=json', $manifest->nextAction);
        self::assertSame($receiptBefore, file_get_contents($receipts->path('VER-1')));
        $this->assertCloseReadinessWaitsForRecoveredPipeline();
    }

    public function testUnchangedCandidateRemainsCompleteWithoutRecoveryEvent(): void
    {
        [$gateway, $before, $build] = $this->completeRun();
        $this->copyCandidateAIntoProjectRoot();

        [$exit, $payload] = $this->recover($build->candidateRevision);

        self::assertSame(0, $exit, json_encode($payload, JSON_THROW_ON_ERROR));
        self::assertTrue($payload['complete']);
        self::assertNull($payload['current_stage']);
        $after = $this->state();
        self::assertSame($before->toArray(), $after->toArray());
        self::assertSame([], $after->recoveryHistory);
    }

    public function testOutOfScopeCandidateChangeFailsClosedWithoutWritingRecoveryState(): void
    {
        [$gateway, , $build] = $this->completeRun();
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/README.md', "Unrelated worktree change.\n");
        $candidateB = $this->currentCandidateRevision();
        $stateStore = new ExecutionStateStore($this->run->root);
        $statePath = $stateStore->path('VER-1');
        $stateBefore = file_get_contents($statePath);

        [$exit, $payload] = $this->recover($candidateB);

        self::assertSame(1, $exit);
        self::assertSame('error', $payload['status']);
        self::assertStringContainsString('outside approved Contract scope', $payload['message']);
        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertTrue($gateway->projection('VER-1')->complete());
    }

    public function testRecoveredBuildRejectsOutOfScopeCandidateObservation(): void
    {
        [$gateway, , $build] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $candidateB = $this->currentCandidateRevision();

        [$recoveryExit, $recoveryPayload] = $this->recover($candidateB);

        self::assertSame(0, $recoveryExit, json_encode($recoveryPayload, JSON_THROW_ON_ERROR));
        $buildBundle = $gateway->prepareStage('VER-1', 'build');
        file_put_contents($this->run->root . '/README.md', "Unrelated worktree change.\n");
        $candidateC = $this->currentCandidateRevision();
        $statePath = (new ExecutionStateStore($this->run->root))->path('VER-1');
        $stateBefore = file_get_contents($statePath);

        try {
            $gateway->recordStageCandidate(new StageCandidateObservation(
                $buildBundle->taskId,
                $buildBundle->runId,
                $buildBundle->contractRevision,
                $buildBundle->executionPlanDigest,
                $buildBundle->stageId,
                $buildBundle->attempt,
                $candidateA,
                $candidateC,
            ));
            self::fail('A recovered build must not register an out-of-scope candidate against the old candidate.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside approved Contract scope', $exception->getMessage());
        }

        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertSame('build', $gateway->projection('VER-1')->currentStageId);
        self::assertSame(2, $gateway->projection('VER-1')->currentAttempt);
    }

    public function testRecoveredHardeningRejectsOutOfScopeCandidate(): void
    {
        [, , $build] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $candidateB = $this->currentCandidateRevision();

        [$recoveryExit, $recoveryPayload] = $this->recover($candidateB);

        self::assertSame(0, $recoveryExit, json_encode($recoveryPayload, JSON_THROW_ON_ERROR));
        file_put_contents($this->run->root . '/README.md', "Unrelated hardening-stage change.\n");
        $candidateC = $this->currentCandidateRevision();
        $basePlan = (new ExecutionPlanStore($this->run->root))->load('VER-1');
        $hardenedPlan = ExecutionPlan::resolve(
            ExecutionProfile::firstParty(ExecutionProfileName::HARDENED),
            $basePlan->taskId,
            $basePlan->runId,
            $basePlan->contractRevision,
            $basePlan->contractSource,
            $basePlan->baseCommit,
            $basePlan->preparedAt,
        );
        $hardeningStage = $hardenedPlan->stage('hardening');
        $result = new StageResult(
            'recovery-hardening-out-of-scope:VER-1:2',
            $hardenedPlan->taskId,
            $hardenedPlan->runId,
            $hardenedPlan->contractRevision,
            $hardenedPlan->digest(),
            $hardeningStage->id,
            $this->state()->currentAttempt,
            StageOutcome::COMPLETED,
            $candidateC,
            [],
            [],
            'Attempt to add an unrelated file during recovered hardening.',
            'ctx:hardening-recovery-out-of-scope',
        );

        try {
            (new ExecutionStageResultAuthority($this->run->root))->assertAcceptable(
                $hardenedPlan,
                $this->state(),
                $hardeningStage,
                $result,
            );
            self::fail('Recovered mutating stages must reject candidates containing out-of-scope changes.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside approved Contract scope', $exception->getMessage());
        }

        self::assertSame($candidateA, $this->state()->recoveryHistory[0]['previous_candidate_revision']);
        self::assertSame('build', $this->state()->currentStageId);
    }

    public function testRecoveredBuildRejectsBackslashPathOutsideExactFileScopeBeforeMutation(): void
    {
        [$gateway, , $build] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $candidateB = $this->currentCandidateRevision();

        [$recoveryExit, $recoveryPayload] = $this->recover($candidateB);

        self::assertSame(0, $recoveryExit, json_encode($recoveryPayload, JSON_THROW_ON_ERROR));
        $buildBundle = $gateway->prepareStage('VER-1', 'build');
        $unsafeRelativePath = 'src/Foo.php\\..\\..\\README.md';
        self::assertNotFalse(file_put_contents($this->run->root . '/' . $unsafeRelativePath, "Outside the exact-file scope.\n"));
        $candidateC = $this->currentCandidateRevision();
        $statePath = (new ExecutionStateStore($this->run->root))->path('VER-1');
        $stateBefore = file_get_contents($statePath);
        $workflowBefore = $this->treeFingerprint($this->run->root . '/.agent-loop');

        try {
            $gateway->recordStageCandidate(new StageCandidateObservation(
                $buildBundle->taskId,
                $buildBundle->runId,
                $buildBundle->contractRevision,
                $buildBundle->executionPlanDigest,
                $buildBundle->stageId,
                $buildBundle->attempt,
                $candidateA,
                $candidateC,
            ));
            self::fail('A literal-backslash path must not be normalized into an exact-file scope descendant.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ambiguous repository path', $exception->getMessage());
        }

        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertSame($workflowBefore, $this->treeFingerprint($this->run->root . '/.agent-loop'));
        self::assertSame('build', $gateway->projection('VER-1')->currentStageId);
        self::assertSame(2, $gateway->projection('VER-1')->currentAttempt);
    }

    public function testRecoveredBuildRejectsDescendantWhenApprovedFileBecomesDirectory(): void
    {
        [$gateway, , $build] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $candidateB = $this->currentCandidateRevision();

        [$recoveryExit, $recoveryPayload] = $this->recover($candidateB);

        self::assertSame(0, $recoveryExit, json_encode($recoveryPayload, JSON_THROW_ON_ERROR));
        $buildBundle = $gateway->prepareStage('VER-1', 'build');
        self::assertTrue(unlink($this->run->root . '/src/Foo.php'));
        self::assertTrue(mkdir($this->run->root . '/src/Foo.php', 0o775));
        self::assertNotFalse(file_put_contents(
            $this->run->root . '/src/Foo.php/README.md',
            "A descendant of a scoped file is outside the approved exact-file scope.\n",
        ));
        $candidateC = $this->currentCandidateRevision();
        $statePath = (new ExecutionStateStore($this->run->root))->path('VER-1');
        $stateBefore = file_get_contents($statePath);
        $workflowBefore = $this->treeFingerprint($this->run->root . '/.agent-loop');

        try {
            $gateway->recordStageCandidate(new StageCandidateObservation(
                $buildBundle->taskId,
                $buildBundle->runId,
                $buildBundle->contractRevision,
                $buildBundle->executionPlanDigest,
                $buildBundle->stageId,
                $buildBundle->attempt,
                $candidateA,
                $candidateC,
            ));
            self::fail('A scoped Git blob must not authorize descendants after replacement with a directory.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside approved Contract scope', $exception->getMessage());
        }

        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertSame($workflowBefore, $this->treeFingerprint($this->run->root . '/.agent-loop'));
        self::assertSame('build', $gateway->projection('VER-1')->currentStageId);
        self::assertSame(2, $gateway->projection('VER-1')->currentAttempt);
    }

    public function testExecutionStateStoreRecomputesRecoveryScopeBeforePersisting(): void
    {
        [$gateway, , $build] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        file_put_contents($this->run->root . '/README.md', "Unrelated worktree change.\n");
        $candidateC = $this->currentCandidateRevision();
        $plan = (new ExecutionPlanStore($this->run->root))->load('VER-1');
        $stateStore = new ExecutionStateStore($this->run->root);
        $statePath = $stateStore->path('VER-1');
        $stateBefore = file_get_contents($statePath);
        $workflowBefore = $this->treeFingerprint($this->run->root . '/.agent-loop');

        try {
            $stateStore->recoverCompleted($plan, $candidateA, $candidateC, ['src/Foo.php']);
            self::fail('The public state store must recompute candidate identity and scope before persisting recovery.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('outside approved Contract scope', $exception->getMessage());
        }

        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertSame($workflowBefore, $this->treeFingerprint($this->run->root . '/.agent-loop'));
        self::assertTrue($gateway->projection('VER-1')->complete());
    }

    public function testExecutionStateStoreRejectsCandidateNotHeldByCurrentWorkspace(): void
    {
        [$gateway, , $build] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $candidateB = $this->currentCandidateRevision();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 3; }\n");
        $plan = (new ExecutionPlanStore($this->run->root))->load('VER-1');
        $stateStore = new ExecutionStateStore($this->run->root);
        $statePath = $stateStore->path('VER-1');
        $stateBefore = file_get_contents($statePath);
        $workflowBefore = $this->treeFingerprint($this->run->root . '/.agent-loop');

        try {
            $stateStore->recoverCompleted($plan, $candidateA, $candidateB, ['src/Foo.php']);
            self::fail('The state store must verify that the supplied candidate matches the current owner workspace.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('CANDIDATE_MISMATCH', $exception->getMessage());
        }

        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertSame($workflowBefore, $this->treeFingerprint($this->run->root . '/.agent-loop'));
        self::assertTrue($gateway->projection('VER-1')->complete());
    }

    public function testScopeDirectoryLookupTreatsPathspecMagicAsLiteral(): void
    {
        $this->completeRun();
        self::assertTrue(mkdir($this->run->root . '/src/Execution', 0o775, true));
        self::assertNotFalse(file_put_contents($this->run->root . '/src/Execution/Marker.php', "<?php\n"));
        $previousCandidate = $this->currentCandidateRevision();
        $literalPath = ':(top)src/Execution/evil.php';
        self::assertTrue(mkdir(dirname($this->run->root . '/' . $literalPath), 0o775, true));
        self::assertNotFalse(file_put_contents($this->run->root . '/' . $literalPath, "<?php\n// literal pathspec name\n"));
        $candidate = $this->currentCandidateRevision();
        $plan = (new ExecutionPlanStore($this->run->root))->load('VER-1');
        $contract = $this->contractWithScope([':(top)src/Execution']);
        $rejection = null;

        try {
            (new CompletedPipelineRecovery($this->run->root))->candidateChangedFilesWithinApprovedScope(
                $plan,
                $contract,
                $previousCandidate,
                $candidate,
            );
        } catch (RuntimeException $exception) {
            $rejection = $exception;
        }
        self::assertNotNull($rejection);
        self::assertStringContainsString('outside approved Contract scope', $rejection->getMessage());
    }

    public function testRecoveredBuildRejectsSymlinkAtApprovedExactFileBeforeMutation(): void
    {
        [$gateway, , $build] = $this->completeRun();
        $candidateA = $build->candidateRevision;
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $candidateB = $this->currentCandidateRevision();

        [$recoveryExit, $recoveryPayload] = $this->recover($candidateB);

        self::assertSame(0, $recoveryExit, json_encode($recoveryPayload, JSON_THROW_ON_ERROR));
        $buildBundle = $gateway->prepareStage('VER-1', 'build');
        self::assertTrue(unlink($this->run->root . '/src/Foo.php'));
        self::assertNotFalse(file_put_contents($this->run->root . '/.agent-loop/symlink-target', "outside candidate content\n"));
        self::assertTrue(symlink('../.agent-loop/symlink-target', $this->run->root . '/src/Foo.php'));
        $candidateC = $this->currentCandidateRevision();
        $statePath = (new ExecutionStateStore($this->run->root))->path('VER-1');
        $stateBefore = file_get_contents($statePath);
        $workflowBefore = $this->treeFingerprint($this->run->root . '/.agent-loop');
        $rejection = null;

        try {
            $gateway->recordStageCandidate(new StageCandidateObservation(
                $buildBundle->taskId,
                $buildBundle->runId,
                $buildBundle->contractRevision,
                $buildBundle->executionPlanDigest,
                $buildBundle->stageId,
                $buildBundle->attempt,
                $candidateA,
                $candidateC,
            ));
        } catch (RuntimeException $exception) {
            $rejection = $exception;
        }

        self::assertNotNull($rejection);
        self::assertStringContainsString('symlink', strtolower($rejection->getMessage()));
        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertSame($workflowBefore, $this->treeFingerprint($this->run->root . '/.agent-loop'));
        self::assertSame('build', $gateway->projection('VER-1')->currentStageId);
        self::assertSame(2, $gateway->projection('VER-1')->currentAttempt);
    }

    public function testRecoveryScopeRejectsSymlinkUnderApprovedDirectory(): void
    {
        $this->completeRun();
        $previousCandidate = $this->currentCandidateRevision();
        self::assertNotFalse(file_put_contents($this->run->root . '/src/Link.php', "<?php\n// replaced by symlink\n"));
        $intermediateCandidate = $this->currentCandidateRevision();
        self::assertTrue(unlink($this->run->root . '/src/Link.php'));
        self::assertNotFalse(file_put_contents($this->run->root . '/.agent-loop/symlink-target', "outside candidate content\n"));
        self::assertTrue(symlink('../.agent-loop/symlink-target', $this->run->root . '/src/Link.php'));
        $candidate = $this->currentCandidateRevision();
        $plan = (new ExecutionPlanStore($this->run->root))->load('VER-1');
        $contract = $this->contractWithScope(['src']);
        $rejection = null;

        try {
            (new CompletedPipelineRecovery($this->run->root))->candidateChangedFilesWithinApprovedScope(
                $plan,
                $contract,
                $previousCandidate,
                $candidate,
            );
        } catch (RuntimeException $exception) {
            $rejection = $exception;
        }
        self::assertNotNull($rejection);
        self::assertStringContainsString('symlink', strtolower($rejection->getMessage()));
        self::assertNotSame($previousCandidate, $intermediateCandidate);
    }

    public function testRepeatingTheSameRecoveryIsIdempotent(): void
    {
        [, , $build] = $this->completeRun();
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 3; }\n");
        $candidateB = $this->currentCandidateRevision();

        [$firstExit, $first] = $this->recover($candidateB);
        [$secondExit, $second] = $this->recover($candidateB);

        self::assertSame(0, $firstExit, json_encode($first, JSON_THROW_ON_ERROR));
        self::assertSame(0, $secondExit, json_encode($second, JSON_THROW_ON_ERROR));
        self::assertSame('build', $second['current_stage']);
        self::assertSame(2, $second['attempt']);
        self::assertCount(1, $this->state()->recoveryHistory);
    }

    public function testCandidateClaimMustMatchTheCurrentOwnerWorkspace(): void
    {
        [$gateway, $before] = $this->completeRun();
        $this->copyCandidateAIntoProjectRoot();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 4; }\n");
        $candidateB = $this->currentCandidateRevision();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 5; }\n");
        $statePath = (new ExecutionStateStore($this->run->root))->path('VER-1');
        $stateBefore = file_get_contents($statePath);

        [$exit, $payload] = $this->recover($candidateB);

        self::assertSame(1, $exit);
        self::assertSame('error', $payload['status']);
        self::assertStringContainsString('CANDIDATE_MISMATCH', $payload['message']);
        self::assertSame($stateBefore, file_get_contents($statePath));
        self::assertTrue($gateway->projection('VER-1')->complete());
        self::assertSame([], $this->state()->recoveryHistory);
        self::assertSame($before->toArray(), $this->state()->toArray());
    }

    public function testPra2ReplayRejectsTrustedExternalWorkflowStateSymlinkWithoutMutation(): void
    {
        [, , $build] = $this->completeRun();
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $contract = (new TaskContractStore($this->run->root))->load('VER-1');
        $snapshot = ImplementationSnapshot::capture($this->run->root, $contract)->digest;

        $statePath = $this->run->root . '/.agent-loop';
        $externalStatePath = $this->run->root . '-external-state';
        self::assertDirectoryDoesNotExist($externalStatePath);
        self::assertTrue(rename($statePath, $externalStatePath));
        self::assertTrue(mkdir($externalStatePath . '/runs/PRA-4', 0o775, true));
        $symlinkCreated = false;
        $oldTrustedPaths = getenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');

        try {
            if (!@symlink($externalStatePath, $statePath)) {
                self::markTestSkipped('symlink() is not available.');
            }
            $symlinkCreated = true;
            $this->trustExternalStatePath($externalStatePath);

            $stateBefore = $this->treeFingerprint($externalStatePath);
            $sourceBefore = file_get_contents($this->run->root . '/src/Foo.php');
            [$exit, $output] = $this->runPra2Replay($snapshot, $build->candidateRevision);

            self::assertSame(1, $exit);
            self::assertStringContainsString('Symbolic links are not supported in an isolated replay', $output);
            self::assertSame($stateBefore, $this->treeFingerprint($externalStatePath));
            self::assertSame($sourceBefore, file_get_contents($this->run->root . '/src/Foo.php'));
        } finally {
            putenv($oldTrustedPaths === false
                ? 'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS'
                : 'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS=' . $oldTrustedPaths);
            if ($symlinkCreated && is_link($statePath)) {
                unlink($statePath);
            }
            if (is_dir($externalStatePath)) {
                rename($externalStatePath, $statePath);
            }
        }
    }

    public function testReplayPathGuardRejectsSymlinkedDestinationParentBeforeWrites(): void
    {
        require_once dirname(__DIR__) . '/tools/Dogfood/PhpRuntimeAuditPra2RecoveryReplay.php';
        $fixtureRoot = $this->run->root . '/.agent-loop/replay-path-guard-test';
        $project = $fixtureRoot . '/project';
        $external = $fixtureRoot . '/external';
        self::assertTrue(mkdir($project, 0o775, true));
        self::assertTrue(mkdir($external, 0o775, true));
        self::assertNotFalse(file_put_contents($external . '/Foo.php', "external sentinel\n"));
        if (!@symlink($external, $project . '/src')) {
            self::markTestSkipped('symlink() is not available.');
        }

        try {
            $externalBefore = $this->treeFingerprint($external);
            try {
                \PhpRuntimeAuditPra2RecoveryReplay::assertNoSymlinkComponents($project, 'src/Foo.php');
                self::fail('A symlinked destination ancestor must block the replay overlay.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Symbolic link', $exception->getMessage());
            }
            self::assertSame($externalBefore, $this->treeFingerprint($external));
        } finally {
            if (is_link($project . '/src')) {
                unlink($project . '/src');
            }
            unlink($external . '/Foo.php');
            rmdir($external);
            rmdir($project);
            rmdir($fixtureRoot);
        }
    }

    /** @return array{ExecutionGateway, ExecutionState, StageResult, StageResult} */
    private function completeRun(): array
    {
        $gateway = new ExecutionGateway($this->run->root);
        self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace)->complete());
        $state = $this->state();

        return [$gateway, $state, $this->resultFor($state, 'build'), $this->resultFor($state, 'review')];
    }

    private function copyCandidateAIntoProjectRoot(): void
    {
        $source = file_get_contents($this->run->workspace . '/src/Foo.php');
        self::assertIsString($source);
        self::assertNotFalse(file_put_contents($this->run->root . '/src/Foo.php', $source));
    }

    private function currentCandidateRevision(): string
    {
        $index = tempnam(sys_get_temp_dir(), 'agent-loop-recovery-index-');
        if (!is_string($index) || !unlink($index)) {
            throw new RuntimeException('Unable to prepare a private Git index for the recovery fixture.');
        }
        $environment = getenv();
        foreach (['GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_COMMON_DIR', 'GIT_OBJECT_DIRECTORY', 'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_PREFIX', 'GIT_NAMESPACE', 'GIT_CEILING_DIRECTORIES'] as $key) {
            unset($environment[$key]);
        }
        $environment['GIT_INDEX_FILE'] = $index;
        try {
            $this->gitWithEnvironment(['read-tree', $this->run->git($this->run->root, 'rev-parse', 'HEAD')], $environment);
            $this->gitWithEnvironment(['add', '-A', '--', '.'], $environment);
            $tree = $this->gitWithEnvironment(['write-tree'], $environment);
        } finally {
            if (is_file($index)) {
                unlink($index);
            }
        }
        $plan = (new ExecutionPlanStore($this->run->root))->load('VER-1');
        if ($plan->baseCommit === null) {
            throw new RuntimeException('Recovery fixture execution plan has no governed base.');
        }

        return 'git-tree-v1:' . $plan->baseCommit . ':' . $tree;
    }

    /** @param list<string> $arguments
     *  @param array<string, string> $environment
     */
    private function gitWithEnvironment(array $arguments, array $environment): string
    {
        $pipes = [];
        $process = proc_open(
            ['git', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->run->root,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to run Git in the recovery fixture.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Git failed in the recovery fixture: ' . trim(is_string($stderr) ? $stderr : ''));
        }

        return trim($stdout);
    }

    /** @return array{int, array<string, mixed>} */
    private function recover(string $candidate): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = (new WorkflowPipelineCommand($this->run->root))->run([
                'recover', 'VER-1', '--candidate', $candidate, '--format=json',
            ]);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exit, json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
    }

    private function state(): ExecutionState
    {
        $state = (new ExecutionStateStore($this->run->root))->find('VER-1');
        self::assertNotNull($state);

        return $state;
    }

    private function trustExternalStatePath(string $path): void
    {
        $canonical = realpath($path);
        self::assertIsString($canonical);
        putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS=' . json_encode([$canonical], JSON_THROW_ON_ERROR));
    }

    /** @return array{int, string} */
    private function runPra2Replay(string $snapshot, string $acceptedCandidate): array
    {
        $script = dirname(__DIR__) . '/tools/Dogfood/PhpRuntimeAuditPra2RecoveryReplay.php';
        $process = proc_open(
            [
                PHP_BINARY,
                $script,
                '--source',
                $this->run->root,
                '--task',
                'VER-1',
                '--expected-snapshot',
                $snapshot,
                '--accepted-candidate',
                $acceptedCandidate,
            ],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            dirname(__DIR__),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the PRA-2 replay safety regression process.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return [$exit, (is_string($stdout) ? $stdout : '') . (is_string($stderr) ? $stderr : '')];
    }

    private function treeFingerprint(string $root): string
    {
        $entries = [];
        $visit = static function (string $path, string $relative) use (&$visit, &$entries): void {
            $stat = lstat($path);
            if ($stat === false) {
                throw new RuntimeException('Unable to inspect external state fixture path: ' . $path);
            }
            if (is_link($path)) {
                $target = readlink($path);
                if (!is_string($target)) {
                    throw new RuntimeException('Unable to read external state fixture link: ' . $path);
                }
                $entries[] = ['path' => $relative, 'type' => 'link', 'target' => $target];

                return;
            }
            if (is_file($path)) {
                $digest = hash_file('sha256', $path);
                if (!is_string($digest)) {
                    throw new RuntimeException('Unable to hash external state fixture file: ' . $path);
                }
                $entries[] = ['path' => $relative, 'type' => 'file', 'sha256' => $digest];

                return;
            }
            if (!is_dir($path)) {
                throw new RuntimeException('Unsupported external state fixture path: ' . $path);
            }
            $entries[] = ['path' => $relative, 'type' => 'directory'];
            foreach (new \DirectoryIterator($path) as $item) {
                if (!$item->isDot()) {
                    $child = $relative === '.' ? $item->getFilename() : $relative . '/' . $item->getFilename();
                    $visit($item->getPathname(), $child);
                }
            }
        };
        $visit($root, '.');
        usort($entries, static fn (array $left, array $right): int => strcmp($left['path'], $right['path']));

        return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function assertCloseReadinessWaitsForRecoveredPipeline(): void
    {
        $contract = (new TaskContractStore($this->run->root))->load('VER-1');
        $run = (new GovernedRunStore($this->run->root))->findForContract($contract);
        self::assertNotNull($run);
        $session = (new SessionStore())->activeForTask((new ProjectLayout($this->run->root))->sessionsRoot(), 'VER-1');
        self::assertNotNull($session);

        $readiness = (new WorkflowCloseReadinessInspector($this->run->root))->inspect($contract, $run, $session);
        self::assertFalse($readiness->isReady());
        $failure = $readiness->firstFailure();
        self::assertNotNull($failure);
        self::assertSame('execution_review', $failure['gate']);
        self::assertStringContainsString('incomplete at stage build', $failure['detail']);
    }

    private function resultFor(ExecutionState $state, string $stageId): StageResult
    {
        foreach ($state->history as $accepted) {
            if ($accepted->result->stageId === $stageId) {
                return $accepted->result;
            }
        }

        self::fail('Expected accepted result for stage ' . $stageId . '.');
    }

    /** @param list<string> $scope */
    private function contractWithScope(array $scope): TaskContract
    {
        $contract = (new TaskContractStore($this->run->root))->load('VER-1');

        return new TaskContract(
            taskId: $contract->taskId,
            goal: $contract->goal,
            scope: $scope,
            nonGoals: $contract->nonGoals,
            validation: $contract->validation,
            status: $contract->status,
            revision: $contract->revision,
            createdAt: $contract->createdAt,
            updatedAt: $contract->updatedAt,
            path: $contract->path,
            plannedBy: $contract->plannedBy,
            baseCommit: $contract->baseCommit,
            tags: $contract->tags,
            behaviorAnchors: $contract->behaviorAnchors,
            operatingPromptManifest: $contract->operatingPromptManifest,
            operatingPrompts: $contract->operatingPrompts,
            approvedBy: $contract->approvedBy,
            approvedAt: $contract->approvedAt,
            acceptanceCriteria: $contract->acceptanceCriteria,
            acceptanceObservations: $contract->acceptanceObservations,
        );
    }
}
