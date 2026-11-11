<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionEvidenceClaim;
use voku\AgentLoop\Execution\ExecutionEvidenceKind;
use voku\AgentLoop\Execution\ExecutionEvidenceStore;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\AttentionResolutionStore;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;
use voku\AgentLoop\Workflow\WorkflowAttentionProjection;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class GovernedExecutionProtocolTest extends TestCase
{
    private string $baseCommit;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-execution-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $git = 'git -C ' . escapeshellarg($this->root);
        exec("$git init -q && $git config user.email tests@example.test && $git config user.name Tests && $git add src/Foo.php .gitignore && $git commit -qm base", $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));
        $this->baseCommit = trim((string) shell_exec("$git rev-parse HEAD"));
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $this->baseCommit);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testStandardProfileIsDefaultAndStartsAtInvestigationStage(): void
    {
        $this->prepareApprovedRun();

        $plan = (new ExecutionPlanStore($this->root))->load('ABC-123');
        self::assertSame(ExecutionProfileName::STANDARD, $plan->profile);
        self::assertNotSame([], $plan->stages);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $plan->digest());

        $projection = (new ExecutionGateway($this->root))->projection('ABC-123');
        self::assertFalse($projection->complete());
        self::assertSame('investigate', $projection->currentStageId);
        self::assertSame(1, $projection->currentAttempt);
        self::assertSame($this->baseCommit, $projection->candidateRevision);
        self::assertFileExists((new ExecutionStateStore($this->root))->path('ABC-123'));
    }

    public function testSurgicalProfileIsResolvedBeforeRunAndAdvancesOnlyThroughAcceptedOwnerBoundResults(): void
    {
        $this->planAndApprove();
        $profile = new WorkflowExecutionProfileCommand($this->root);
        ob_start();
        $profileExit = $profile->run(['ABC-123', '--profile', 'surgical', '--by', 'lars']);
        ob_end_clean();
        self::assertSame(0, $profileExit);
        $this->enter();

        $gateway = new ExecutionGateway($this->root);
        $projection = $gateway->projection('ABC-123');
        self::assertSame(ExecutionProfileName::SURGICAL, $projection->profile);
        self::assertSame('investigate', $projection->currentStageId);
        self::assertSame(1, $projection->currentAttempt);
        self::assertSame($this->baseCommit, $projection->candidateRevision);

        $bundle = $gateway->prepareStage('ABC-123', 'investigate');
        self::assertFalse($bundle->mayMutate);
        self::assertSame('investigator', $bundle->roleId);
        self::assertSame($this->baseCommit, $bundle->baseCommit);
        self::assertContains(StageOutcome::COMPLETED, $bundle->acceptedOutcomes);
        self::assertSame(['src/Foo.php'], $bundle->allowedScope);
        self::assertStringContainsString('A successful process exit is not workflow approval.', $bundle->prompt);
        self::assertStringNotContainsString('disposable task-local work checks', $bundle->prompt);

        $artifactReference = (new ExecutionEvidenceStore($this->root))->record(new ExecutionEvidenceClaim(
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $bundle->candidateRevision,
            ExecutionEvidenceKind::ARTIFACT,
            'owner:investigation',
            'sha256:' . hash('sha256', 'owner:investigation'),
        ));
        $result = new StageResult(
            ' submission:investigate:1 ',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            ' ' . $bundle->candidateRevision . ' ',
            [$artifactReference],
            [],
            "Investigation complete.\n",
        );
        $after = $gateway->submitStageResult($result);
        self::assertSame('build', $after->currentStageId);
        self::assertSame(1, $after->currentAttempt);
        self::assertSame($this->baseCommit, $after->candidateRevision);
        self::assertCount(1, $after->handoffs);
        self::assertSame('investigate', $after->handoffs[0]->fromStage);
        self::assertSame('build', $after->handoffs[0]->toStage);
        self::assertSame([$artifactReference], $after->handoffs[0]->artifactReferences);

        $duplicate = $gateway->submitStageResult(new StageResult(
            'submission:investigate:1',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $bundle->candidateRevision,
            [$artifactReference],
            [],
            'Investigation complete.',
        ));
        self::assertSame('build', $duplicate->currentStageId);
        self::assertSame($this->baseCommit, $duplicate->candidateRevision);
        self::assertCount(1, $duplicate->handoffs, 'Retrying the same accepted submission must be idempotent.');
    }

    public function testStageResultWithStalePlanDigestFailsClosed(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $bundle = $gateway->prepareStage('ABC-123', 'investigate');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('current execution stage binding');

        $gateway->submitStageResult(new StageResult(
            'submission:stale',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            'sha256:' . str_repeat('0', 64),
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            $bundle->candidateRevision,
            [],
            [],
            'Stale candidate.',
        ));
    }

    public function testForgedCandidateIdentityFailsClosedForReadOnlyStage(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $bundle = $gateway->prepareStage('ABC-123', 'investigate');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        $gateway->submitStageResult(new StageResult(
            'submission:forged',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            'candidate:forged',
            [],
            [],
            'Forged candidate.',
        ));
    }

    public function testMutatingCandidateMustRemainDerivedFromGovernedBase(): void
    {
        $this->prepareSurgicalRun();
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
        self::assertStringContainsString('disposable task-local work checks', $build->prompt);
        self::assertStringContainsString('explicitly permitted by task or repository instructions', $build->prompt);
        self::assertStringContainsString('does not authorize other edits outside the approved scope', $build->prompt);
        self::assertStringContainsString('host and sandbox restrictions', $build->prompt);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_CANDIDATE');

        $gateway->submitStageResult(new StageResult(
            'submission:wrong-base',
            $build->taskId,
            $build->runId,
            $build->contractRevision,
            $build->executionPlanDigest,
            $build->stageId,
            $build->attempt,
            StageOutcome::COMPLETED,
            'git-worktree-v1:' . str_repeat('2', 40) . ':sha256:' . str_repeat('a', 64),
            [],
            [],
            'Build from wrong base.',
        ));
    }

    public function testOwnerEvidenceBoundToAnotherAttemptOrKindFailsClosed(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $bundle = $gateway->prepareStage('ABC-123', 'investigate');
        $store = new ExecutionEvidenceStore($this->root);

        foreach ([ExecutionEvidenceKind::ARTIFACT, ExecutionEvidenceKind::VALIDATION] as $kind) {
            $reference = $store->record(new ExecutionEvidenceClaim(
                $bundle->taskId,
                $bundle->runId,
                $bundle->contractRevision,
                $bundle->executionPlanDigest,
                $bundle->stageId,
                $bundle->attempt + 1,
                $bundle->candidateRevision,
                $kind,
                'owner:stale:' . $kind->value,
                'sha256:' . hash('sha256', 'owner:stale:' . $kind->value),
            ));

            try {
                $gateway->submitStageResult(new StageResult(
                    'submission:stale-' . $kind->value,
                    $bundle->taskId,
                    $bundle->runId,
                    $bundle->contractRevision,
                    $bundle->executionPlanDigest,
                    $bundle->stageId,
                    $bundle->attempt,
                    StageOutcome::COMPLETED,
                    $bundle->candidateRevision,
                    $kind === ExecutionEvidenceKind::ARTIFACT ? [$reference] : [],
                    $kind === ExecutionEvidenceKind::VALIDATION ? [$reference] : [],
                    'Stale evidence.',
                ));
                self::fail('Stale owner evidence must fail closed.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('STALE_EVIDENCE', $exception->getMessage());
            }
        }
    }

    public function testDeterministicVerifyPassRequiresAnOwnerConsistencyReceipt(): void
    {
        $this->prepareSurgicalRun('php -l src/Foo.php');
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
        $candidate = $build->candidateRevision;
        $gateway->submitStageResult(new StageResult(
            'submission:build',
            $build->taskId,
            $build->runId,
            $build->contractRevision,
            $build->executionPlanDigest,
            $build->stageId,
            $build->attempt,
            StageOutcome::COMPLETED,
            $candidate,
            [],
            [],
            'Build complete.',
            'ctx:build',
        ));

        $review = $gateway->prepareStage('ABC-123', 'review');
        $gateway->submitStageResult(new StageResult(
            'submission:review',
            $review->taskId,
            $review->runId,
            $review->contractRevision,
            $review->executionPlanDigest,
            $review->stageId,
            $review->attempt,
            StageOutcome::PASS,
            $candidate,
            [],
            [],
            'Review passed.',
            'ctx:review',
        ));

        $verify = $gateway->prepareStage('ABC-123', 'verify');
        self::assertStringNotContainsString('Required validation is executed by the deterministic verify stage on this exact candidate after this stage', $verify->prompt);
        try {
            $gateway->submitStageResult(new StageResult(
                'submission:verify-without-evidence',
                $verify->taskId,
                $verify->runId,
                $verify->contractRevision,
                $verify->executionPlanDigest,
                $verify->stageId,
                $verify->attempt,
                StageOutcome::PASS,
                $candidate,
                [],
                [],
                'Verify passed without owner evidence.',
            ));
            self::fail('Deterministic PASS without owner consistency evidence must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('MISSING_EVIDENCE', $exception->getMessage());
        }
    }

    public function testClarificationRequiresAuthoritativeHumanResolutionBeforeResume(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $bundle = $gateway->prepareStage('ABC-123', 'investigate');

        $waiting = $gateway->submitStageResult(new StageResult(
            'submission:clarify',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::NEEDS_CLARIFICATION,
            $bundle->candidateRevision,
            [],
            [],
            'Which compatibility boundary is authoritative?',
        ));
        self::assertNotNull($waiting->attention);
        self::assertSame('investigate', $waiting->currentStageId);
        self::assertSame(1, $waiting->currentAttempt);
        self::assertSame($this->baseCommit, $waiting->candidateRevision);

        $attention = $waiting->attention;
        try {
            $gateway->resolveAttention('ABC-123', $attention->id);
            self::fail('Runner-facing Attention resolution must require owner authority.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ATTENTION_RESOLUTION_REQUIRED', $exception->getMessage());
        }

        $service = new WorkflowHumanDecisionService($this->root);
        $reviewed = $service->pendingAttention('ABC-123');
        self::assertNotNull($reviewed);
        self::assertSame([WorkflowAttentionProjection::RESOLVE], $reviewed->actions);
        self::assertSame($bundle->contractRevision, $reviewed->contractRevision);
        self::assertSame($bundle->allowedScope, $reviewed->scope);
        $stale = new WorkflowAttentionProjection(
            $reviewed->attention,
            $reviewed->contractRevision,
            $reviewed->scope,
            $reviewed->executionPlanDigest,
            $reviewed->attempt + 1,
            $reviewed->candidateRevision,
            $reviewed->headCommit,
        );
        try {
            $service->resolveAttention('ABC-123', $attention->id, 'lars', 'Use the public compatibility boundary.', $stale);
            self::fail('A stale reviewed attempt must not resolve Attention.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ATTENTION_RESOLUTION_STALE', $exception->getMessage());
        }
        $git = 'git -C ' . escapeshellarg($this->root);
        exec("$git commit --allow-empty -qm changed-head", $output, $exit);
        self::assertSame(0, $exit);
        try {
            $service->resolveAttention('ABC-123', $attention->id, 'lars', 'Use the public compatibility boundary.', $reviewed);
            self::fail('A changed repository HEAD must not resolve the reviewed Attention.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ATTENTION_RESOLUTION_STALE', $exception->getMessage());
        }
        exec("$git reset --soft " . escapeshellarg($this->baseCommit), $output, $exit);
        self::assertSame(0, $exit);
        $resumed = $service->resolveAttention('ABC-123', $attention->id, 'lars', 'Use the public compatibility boundary.', $reviewed);
        $history = $service->attentionResolutions('ABC-123');
        self::assertCount(1, $history);
        self::assertSame($attention->id, $history[0]->attentionId);
        self::assertSame('Use the public compatibility boundary.', $history[0]->response);
        self::assertNull($resumed->attention);
        self::assertSame('investigate', $resumed->currentStageId);
        self::assertSame(2, $resumed->currentAttempt);
        self::assertSame($this->baseCommit, $resumed->candidateRevision);
        $nextBundle = $gateway->prepareStage('ABC-123', 'investigate');
        self::assertSame('Use the public compatibility boundary.', $nextBundle->attentionResolution?->response);
        self::assertStringContainsString('Use the public compatibility boundary.', $nextBundle->prompt);
        try {
            $service->resolveAttention('ABC-123', $attention->id, 'lars');
            self::fail('A duplicate human resolution must not advance another attempt.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('No matching pending Attention', $exception->getMessage());
        }
        self::assertSame(2, $gateway->projection('ABC-123')->currentAttempt);
    }

    public function testInterruptedResolutionCompletesWithTheRecordedDecision(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $bundle = $gateway->prepareStage('ABC-123', 'investigate');
        $waiting = $gateway->submitStageResult(new StageResult(
            'submission:clarify-interrupted',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::NEEDS_CLARIFICATION,
            $bundle->candidateRevision,
            [],
            [],
            'Which compatibility boundary is authoritative?',
        ));
        $attention = $waiting->attention;
        self::assertNotNull($attention);

        // The record is written but the state transition never happened.
        $plan = (new ExecutionPlanStore($this->root))->load('ABC-123');
        (new AttentionResolutionStore($this->root))->record($plan, $attention, $waiting->currentAttempt, 'lars', 'Keep the public API.');
        self::assertNotNull($gateway->projection('ABC-123')->attention);

        // A retry with different input completes the interrupted transition
        // with the decision already on record; it never overwrites it.
        $service = new WorkflowHumanDecisionService($this->root);
        $resumed = $service->resolveAttention('ABC-123', $attention->id, 'someone-else', 'Retyped response.');
        self::assertNull($resumed->attention);
        self::assertSame(2, $resumed->currentAttempt);
        $history = $service->attentionResolutions('ABC-123');
        self::assertCount(1, $history);
        self::assertSame('lars', $history[0]->actor);
        self::assertSame('Keep the public API.', $history[0]->response);
        $nextBundle = $gateway->prepareStage('ABC-123', 'investigate');
        self::assertSame('Keep the public API.', $nextBundle->attentionResolution?->response);
    }

    public function testCliResolutionSuppliesResponseToResumedStageAndRejectsReplay(): void
    {
        $this->prepareSurgicalRun();
        $gateway = new ExecutionGateway($this->root);
        $bundle = $gateway->prepareStage('ABC-123', 'investigate');
        $sessionId = (new GovernedRunStore($this->root))->find('ABC-123')?->sessionId;
        $waiting = $gateway->submitStageResult(new StageResult(
            'submission:cli-clarify',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::NEEDS_CLARIFICATION,
            $bundle->candidateRevision,
            [],
            [],
            'What should happen?',
        ));
        self::assertNotNull($waiting->attention);
        $command = [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', 'workflow', 'attention', 'ABC-123',
            '--resolve', $waiting->attention->id, '--by', 'lars', '--response', 'Keep the original behavior.'];
        $run = function () use ($command): int {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
            self::assertIsResource($process);
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return proc_close($process);
        };
        $processes = [];
        foreach ([1, 2] as $_) {
            $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
            self::assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }
        $exits = [];
        foreach ($processes as [$process, $pipes]) {
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exits[] = proc_close($process);
        }
        sort($exits);
        self::assertSame([0, 1], $exits, 'Exactly one concurrent human resolution may succeed.');
        $resumed = $gateway->prepareStage('ABC-123', 'investigate');
        self::assertSame($bundle->runId, $resumed->runId);
        self::assertNotNull($sessionId);
        self::assertSame($sessionId, (new GovernedRunStore($this->root))->find('ABC-123')?->sessionId);
        self::assertSame(2, $resumed->attempt);
        self::assertSame('Keep the original behavior.', $resumed->attentionResolution?->response);
        $stageCommand = [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', 'pipeline', 'stage', 'ABC-123', '--format=json'];
        $stageProcess = proc_open($stageCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $stagePipes, $this->root);
        self::assertIsResource($stageProcess);
        fclose($stagePipes[0]);
        $stageOutput = stream_get_contents($stagePipes[1]);
        $stageError = stream_get_contents($stagePipes[2]);
        fclose($stagePipes[1]);
        fclose($stagePipes[2]);
        self::assertSame(0, proc_close($stageProcess), is_string($stageError) ? $stageError : '');
        self::assertIsString($stageOutput);
        self::assertStringContainsString('Keep the original behavior.', $stageOutput);
        self::assertSame(1, $run());
        self::assertSame(2, $gateway->projection('ABC-123')->currentAttempt);
    }

    public function testExecutionProfileCannotChangeAfterRunExists(): void
    {
        $this->prepareSurgicalRun();

        ob_start();
        $exit = (new WorkflowExecutionProfileCommand($this->root))->run([
            'ABC-123', '--profile', 'hardened', '--by', 'lars',
        ]);
        ob_end_clean();

        self::assertSame(1, $exit);
        self::assertSame(ExecutionProfileName::SURGICAL, (new ExecutionPlanStore($this->root))->load('ABC-123')->profile);
    }

    public function testSelectionFromPreviousContractRevisionDoesNotBlockReplacementRun(): void
    {
        $this->planAndApprove();
        ob_start();
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'ABC-123', '--profile', 'surgical', '--by', 'lars',
        ]));
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'ABC-123',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Revise governed external stage execution.',
            '--validation', 'vendor/bin/phpunit',
            '--base-commit', $this->baseCommit,
        ]));
        $revision = (new TaskContractStore($this->root))->load('ABC-123')->revision;
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--revision', (string) $revision, '--by', 'lars']));
        $profileExit = (new WorkflowExecutionProfileCommand($this->root))->run(['ABC-123']);
        $profileOutput = trim((string) ob_get_clean());

        self::assertSame(0, $profileExit);
        self::assertStringEndsWith('standard', $profileOutput);
        self::assertSame(2, (new TaskContractStore($this->root))->load('ABC-123')->revision);

        $this->enter();
        self::assertSame(ExecutionProfileName::STANDARD, (new ExecutionPlanStore($this->root))->load('ABC-123')->profile);
    }

    public function testPersistedPlanDigestDetectsTampering(): void
    {
        $this->prepareSurgicalRun();
        $store = new ExecutionPlanStore($this->root);
        $path = $store->path('ABC-123');
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $decoded['profile'] = 'hardened';
        file_put_contents($path, json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('digest does not match persisted content');
        $store->load('ABC-123');
    }

    private function prepareApprovedRun(): void
    {
        $this->planAndApprove();
        $this->enter();
    }

    private function prepareSurgicalRun(string $validation = 'vendor/bin/phpunit'): void
    {
        $this->planAndApprove($validation);
        ob_start();
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'ABC-123', '--profile', 'surgical', '--by', 'lars',
        ]));
        ob_end_clean();
        $this->enter();
    }

    private function planAndApprove(string $validation = 'vendor/bin/phpunit'): void
    {
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'ABC-123',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Prove governed external stage execution.',
            '--validation', $validation,
            '--base-commit', $this->baseCommit,
        ]));
        $revision = (new TaskContractStore($this->root))->load('ABC-123')->revision;
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--revision', (string) $revision, '--by', 'lars']));
        ob_end_clean();
        self::assertSame('approved', (new TaskContractStore($this->root))->load('ABC-123')->status);
    }

    private function enter(): void
    {
        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
        ))->run('enter', ['ABC-123', '--format=json']);
        ob_end_clean();
        self::assertSame(0, $exit);
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
