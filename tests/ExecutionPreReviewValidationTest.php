<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfile;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Tests\Support\VerifyStageRunFixture;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;

final class ExecutionPreReviewValidationTest extends TestCase
{
    public function testProfilesRouteBuildThroughValidationAndManualHasNoPipeline(): void
    {
        self::assertSame([], ExecutionProfile::firstParty(ExecutionProfileName::MANUAL)->stages);
        foreach ([ExecutionProfileName::SURGICAL, ExecutionProfileName::STANDARD, ExecutionProfileName::HARDENED] as $profile) {
            $stages = ExecutionProfile::firstParty($profile)->stages;
            $indexed = [];
            foreach ($stages as $stage) {
                $indexed[$stage->id] = $stage;
            }
            self::assertSame('pre-review-validation', $indexed['build']->next(StageOutcome::COMPLETED));
            self::assertSame('build', $indexed['pre-review-validation']->next(StageOutcome::FAILED));
            self::assertSame($profile === ExecutionProfileName::SURGICAL ? 'review' : 'correctness-review', $indexed['pre-review-validation']->next(StageOutcome::PASS));
            if ($profile === ExecutionProfileName::HARDENED) {
                self::assertTrue($indexed['hardening']->mayMutate);
                self::assertSame('pre-independent-validation', $indexed['hardening']->next(StageOutcome::COMPLETED));
                self::assertSame('hardening', $indexed['pre-independent-validation']->next(StageOutcome::FAILED));
                self::assertSame('independent-verification', $indexed['pre-independent-validation']->next(StageOutcome::PASS));
            }
            self::assertArrayHasKey('verify', $indexed);
        }
    }

    public function testHardenedCandidateIsValidatedAfterTheMutatingHardeningStage(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $validation = 'php -r "if (str_contains(file_get_contents(\'src/Foo.php\'), \'X = 2\')) { fwrite(STDERR, \'hardening validation failed\'); exit(5); }"';
            $fixture->reachPreReviewWith(
                "<?php\nfinal class Foo { public const int X = 1; }\n",
                [$validation],
                profile: ExecutionProfileName::HARDENED,
            );
            $gateway = new ExecutionGateway($fixture->root);
            self::assertSame('correctness-review', $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace)->currentStageId);
            $initialCandidate = $gateway->projection('VER-1')->candidateRevision;
            $fixture->submit($gateway, $gateway->prepareStage('VER-1', 'correctness-review'), StageOutcome::PASS, $initialCandidate, 'ctx:correctness');
            $fixture->submit($gateway, $gateway->prepareStage('VER-1', 'architecture-review'), StageOutcome::PASS, $initialCandidate, 'ctx:architecture');

            file_put_contents($fixture->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int X = 2; }\n");
            $hardening = $gateway->prepareStage('VER-1', 'hardening');
            $candidate = $fixture->candidate();
            $gateway->recordStageCandidate(new StageCandidateObservation(
                $hardening->taskId,
                $hardening->runId,
                $hardening->contractRevision,
                $hardening->executionPlanDigest,
                $hardening->stageId,
                $hardening->attempt,
                $hardening->candidateRevision,
                $candidate,
            ));
            $fixture->submit($gateway, $hardening, StageOutcome::COMPLETED, $candidate, 'ctx:hardening');

            $failed = $gateway->runDeterministicStage('VER-1', 'pre-independent-validation', $fixture->workspace);
            self::assertSame('hardening', $failed->currentStageId);
            $repair = $gateway->prepareStage('VER-1', 'hardening');
            self::assertStringContainsString($validation, $repair->prompt);
            self::assertStringContainsString('hardening validation failed', $repair->prompt);

            file_put_contents($fixture->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int X = 1; }\n");
            $repair = $gateway->prepareStage('VER-1', 'hardening');
            $candidate = $fixture->candidate();
            $gateway->recordStageCandidate(new StageCandidateObservation(
                $repair->taskId,
                $repair->runId,
                $repair->contractRevision,
                $repair->executionPlanDigest,
                $repair->stageId,
                $repair->attempt,
                $repair->candidateRevision,
                $candidate,
            ));
            $fixture->submit($gateway, $repair, StageOutcome::COMPLETED, $candidate, 'ctx:hardening-repair');
            self::assertSame('independent-verification', $gateway->runDeterministicStage('VER-1', 'pre-independent-validation', $fixture->workspace)->currentStageId);
        } finally {
            $fixture->remove();
        }
    }

    public function testFailureReturnsToBuildAndEveryRevisedCandidateIsValidated(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $fixture->reachPreReviewWith("<?php\nthis is broken\n");
            $gateway = new ExecutionGateway($fixture->root);
            $failed = $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            self::assertSame('build', $failed->currentStageId);
            self::assertNull($failed->attention);
            $build = $gateway->prepareStage('VER-1', 'build');
            self::assertStringContainsString('[FAIL] php -l src/Foo.php', $build->prompt);
            self::assertStringContainsString('Parse error', $build->prompt);
            $this->build($fixture, $gateway, "<?php\nfinal class Foo { public const int X = 1; }\n", 'ctx:fix');
            $passed = $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            self::assertSame('review', $passed->currentStageId);
            $state = (new ExecutionStateStore($fixture->root))->find('VER-1');
            self::assertNotNull($state);
            $history = $state->history;
            $last = end($history);
            self::assertNotFalse($last);
            $oldEvidence = $last->result->validationReferences;
            $candidate = $passed->candidateRevision;
            $fixture->submit($gateway, $gateway->prepareStage('VER-1', 'review'), StageOutcome::CHANGES_REQUIRED, $candidate, 'ctx:review');
            self::assertSame('build', $gateway->projection('VER-1')->currentStageId);
            $this->build($fixture, $gateway, "<?php\nfinal class Foo { public const int X = 2; }\n", 'ctx:second-fix');
            self::assertSame('pre-review-validation', $gateway->projection('VER-1')->currentStageId);
            $bundle = $gateway->prepareStage('VER-1', 'pre-review-validation');
            try {
                (new ExecutionStateStore($fixture->root))->accept((new ExecutionPlanStore($fixture->root))->load('VER-1'), new StageResult(
                    'submission:stale',
                    $bundle->taskId,
                    $bundle->runId,
                    $bundle->contractRevision,
                    $bundle->executionPlanDigest,
                    $bundle->stageId,
                    $bundle->attempt,
                    StageOutcome::PASS,
                    $bundle->candidateRevision,
                    [],
                    $oldEvidence,
                    'Forged replay.',
                ));
                self::fail('Evidence from a prior candidate must not authorize review.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('STALE_EVIDENCE', $exception->getMessage());
            }
            $second = $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            self::assertSame('review', $second->currentStageId);
            self::assertNotSame($candidate, $second->candidateRevision);
            $fixture->submit($gateway, $gateway->prepareStage('VER-1', 'review'), StageOutcome::PASS, $second->candidateRevision, 'ctx:review-2');
            self::assertSame('verify', $gateway->projection('VER-1')->currentStageId);
            self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $fixture->workspace)->complete());
            file_put_contents($fixture->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int X = 3; }\n");
            $recovered = $gateway->recoverCompletedPipeline('VER-1', $fixture->candidate(), $fixture->workspace);
            self::assertSame('build', $recovered->currentStageId);
            $this->build($fixture, $gateway, "<?php\nfinal class Foo { public const int X = 3; }\n", 'ctx:recovery');
            self::assertSame('pre-review-validation', $gateway->projection('VER-1')->currentStageId);
            self::assertSame('review', $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace)->currentStageId);
        } finally {
            $fixture->remove();
        }
    }

    public function testValidationThatMutatesTheCandidateReturnsToRepair(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $fixture->reachPreReviewWith(
                "<?php\n// trigger\nfinal class Foo {}\n",
                ["php -r \"if (str_contains(file_get_contents('src/Foo.php'), 'trigger')) file_put_contents('src/Foo.php', 'changed');\""],
            );
            $gateway = new ExecutionGateway($fixture->root);
            $failed = $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            self::assertSame('build', $failed->currentStageId);
            self::assertStringContainsString('changed the governed candidate', $gateway->prepareStage('VER-1', 'build')->prompt);
            $this->build($fixture, $gateway, "<?php\nfinal class Foo { public const int X = 1; }\n", 'ctx:mutation-repair');
            self::assertSame('review', $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace)->currentStageId);
        } finally {
            $fixture->remove();
        }
    }

    public function testFailingCommandThatMutatesCandidateRetainsCommandAndOutput(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $validation = 'php -r "file_put_contents(\'src/Foo.php\', \'changed\'); fwrite(STDERR, \'specific failure\'); exit(4);"';
            $fixture->reachPreReviewWith("<?php\nfinal class Foo {}\n", [$validation]);
            $gateway = new ExecutionGateway($fixture->root);
            $failed = $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            self::assertSame('build', $failed->currentStageId);
            $prompt = $gateway->prepareStage('VER-1', 'build')->prompt;
            self::assertStringContainsString($validation, $prompt);
            self::assertStringContainsString('specific failure', $prompt);
            self::assertStringContainsString('exit 4', $prompt);
        } finally {
            $fixture->remove();
        }
    }

    public function testValidationCannotRestoreAnIntermediateMutationBetweenCommands(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $source = "<?php\nthis is broken\n";
            $fixture->reachPreReviewWith($source, [
                'php -r "copy(\'src/Foo.php\', \'original.txt\'); file_put_contents(\'src/Foo.php\', \'<?php echo 1;\');"',
                'php -l src/Foo.php',
                'php -r "copy(\'original.txt\', \'src/Foo.php\');"',
            ]);
            $gateway = new ExecutionGateway($fixture->root);
            $failed = $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            self::assertSame('build', $failed->currentStageId);
            self::assertStringContainsString('changed the governed candidate', $gateway->prepareStage('VER-1', 'build')->prompt);
            self::assertNotSame($source, file_get_contents($fixture->workspace . '/src/Foo.php'));
        } finally {
            $fixture->remove();
        }
    }

    public function testPipelineRunReportsAnUnavailableCandidateAndRoutesToRepair(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $fixture->reachPreReviewWith("<?php\nfinal class Foo { public const int X = 1; }\n");
            $command = new WorkflowPipelineCommand($fixture->root);
            ob_start();
            $statusExit = $command->run(['status', 'VER-1', '--format=json']);
            $status = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $statusExit);
            self::assertSame('command_template', $status['next_action_kind']);
            self::assertStringContainsString('--workspace "<workspace-with-candidate>"', $status['next_action']);
            ob_start();
            $exit = $command->run(['run', 'VER-1', '--workspace=' . $fixture->root, '--format=json']);
            $output = (string) ob_get_clean();
            $result = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(1, $exit);
            self::assertSame('build', $result['current_stage']);
            self::assertStringContainsString('CANDIDATE_MISMATCH', $result['validation_failure']);
            self::assertStringContainsString('pipeline stage VER-1', $result['next_action']);
        } finally {
            $fixture->remove();
        }
    }

    public function testBuildSubmissionKeepsTheExplicitWorkspaceForValidation(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $fixture->reachPreReviewWith(
                "<?php\n// trigger\nfinal class Foo {}\n",
                ["php -r \"if (str_contains(file_get_contents('src/Foo.php'), 'trigger')) file_put_contents('src/Foo.php', 'changed');\""],
            );
            $gateway = new ExecutionGateway($fixture->root);
            $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            file_put_contents($fixture->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int X = 1; }\n");

            $command = new WorkflowPipelineCommand($fixture->root);
            ob_start();
            $exit = $command->run(['submit', 'VER-1', '--outcome=completed', '--summary=fixed', '--context-id=ctx:repair', '--workspace=' . $fixture->workspace, '--format=json']);
            $result = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit);
            self::assertSame('pre-review-validation', $result['current_stage']);
            self::assertSame('agent-loop pipeline run VER-1 --workspace ' . escapeshellarg($fixture->workspace), $result['next_action']);
            ob_start();
            $exit = $command->run(['run', 'VER-1', '--workspace=' . $fixture->workspace, '--format=json']);
            $validated = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit);
            self::assertSame('review', $validated['current_stage']);
        } finally {
            $fixture->remove();
        }
    }

    public function testDeferredValidationKeepsTheExplicitCandidateWorkspaceInItsNextAction(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $fixture->reachPreReviewWith("<?php\nfinal class Foo { public const int X = 1; }\n");
            $command = new WorkflowPipelineCommand($fixture->root);
            ob_start();
            $exit = $command->run(['run', 'VER-1', '--auto-verify=false', '--workspace=' . $fixture->workspace, '--format=json']);
            $deferred = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit);
            self::assertSame('agent-loop pipeline run VER-1 --workspace ' . escapeshellarg($fixture->workspace), $deferred['next_action']);
            self::assertSame('command', $deferred['next_action_kind']);

            ob_start();
            $exit = $command->run(['run', 'VER-1', '--workspace=' . $fixture->workspace, '--format=json']);
            $completed = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit);
            self::assertSame('review', $completed['current_stage']);
        } finally {
            $fixture->remove();
        }
    }

    public function testPassingReviewPreservesCandidateWorkspaceForFinalVerify(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $fixture->reachPreReviewWith("<?php\nfinal class Foo { public const int X = 1; }\n");
            $gateway = new ExecutionGateway($fixture->root);
            $gateway->runDeterministicStage('VER-1', 'pre-review-validation', $fixture->workspace);
            $command = new WorkflowPipelineCommand($fixture->root);
            ob_start();
            $exit = $command->run([
                'submit', 'VER-1', '--outcome=pass', '--context-id=ctx:review-final', '--artifact=src/Foo.php',
                '--workspace=' . $fixture->workspace, '--format=json',
            ]);
            $transition = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $exit);
            self::assertSame('verify', $transition['current_stage']);
            self::assertSame('agent-loop pipeline run VER-1 --workspace ' . escapeshellarg($fixture->workspace), $transition['next_action']);

            ob_start();
            $statusExit = $command->run(['status', 'VER-1', '--format=json']);
            $status = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(0, $statusExit);
            self::assertSame('command_template', $status['next_action_kind']);
            self::assertStringContainsString('--workspace "<workspace-with-candidate>"', $status['next_action']);
        } finally {
            $fixture->remove();
        }
    }

    public function testHostCannotSubmitAPreReviewPassWithoutRunningValidation(): void
    {
        $fixture = new VerifyStageRunFixture();
        try {
            $fixture->reachPreReviewWith("<?php\nthis is broken\n");
            $gateway = new ExecutionGateway($fixture->root);
            $bundle = $gateway->prepareStage('VER-1', 'pre-review-validation');
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('must be executed by the owner');
            $gateway->submitStageResult(new StageResult(
                'submission:forged',
                $bundle->taskId,
                $bundle->runId,
                $bundle->contractRevision,
                $bundle->executionPlanDigest,
                $bundle->stageId,
                $bundle->attempt,
                StageOutcome::PASS,
                $bundle->candidateRevision,
                [],
                [],
                'Claimed pass.',
            ));
        } finally {
            $fixture->remove();
        }
    }

    private function build(VerifyStageRunFixture $fixture, ExecutionGateway $gateway, string $source, string $context): void
    {
        file_put_contents($fixture->workspace . '/src/Foo.php', $source);
        $bundle = $gateway->prepareStage('VER-1', 'build');
        $candidate = $fixture->candidate();
        $gateway->recordStageCandidate(new StageCandidateObservation(
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $bundle->candidateRevision,
            $candidate,
        ));
        $fixture->submit($gateway, $bundle, StageOutcome::COMPLETED, $candidate, $context);
    }
}
