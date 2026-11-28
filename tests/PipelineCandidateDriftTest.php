<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Tests\Support\VerifyStageRunFixture;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowHumanDecisionService;
use voku\AgentLoop\Workflow\WorkflowCloseReadinessInspector;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentSession\SessionStore;

/**
 * A governed candidate that changes after its fresh-context reviews passed must
 * never carry those reviews over: the close gate and the pipeline commands
 * route to `pipeline recover`, which reopens build and makes the reviews stale,
 * both after completion and while the Run waits at verify (#1198).
 */
final class PipelineCandidateDriftTest extends TestCase
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

    public function testAnEditAtVerifyAfterReviewsPassedRoutesToRecoveryAndReopensBuild(): void
    {
        $reviewed = $this->state()->candidateRevision;
        self::assertSame('verify', $this->state()->currentStageId);
        file_put_contents($this->run->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $changed = $this->candidate($this->run->workspace);
        self::assertNotSame($reviewed, $changed);

        [, $status] = $this->pipeline(['status', 'VER-1', '--workspace=' . $this->run->workspace]);
        self::assertSame('candidate_changed', $status['status']);
        self::assertSame($changed, $status['workspace_candidate']);
        self::assertSame('agent-loop pipeline recover VER-1 --candidate ' . $changed . ' --workspace ' . escapeshellarg($this->run->workspace), $status['next_action']);

        [, $run] = $this->pipeline(['run', 'VER-1', '--workspace=' . $this->run->workspace]);
        self::assertSame($status['next_action'], $run['next_action'], 'pipeline run must not dead-end at verify.');
        self::assertSame('verify', $this->state()->currentStageId, 'Routing alone never changes execution state.');

        $historyBefore = $this->state()->toArray()['history'];
        [$exit, $recovered] = $this->pipeline(['recover', 'VER-1', '--candidate', $changed, '--workspace=' . $this->run->workspace]);
        self::assertSame(0, $exit, json_encode($recovered, JSON_THROW_ON_ERROR));
        self::assertSame('build', $recovered['current_stage']);

        $after = $this->state();
        self::assertSame($historyBefore, $after->toArray()['history'], 'Earlier results stay immutable history.');
        self::assertCount(1, $after->recoveryHistory);
        self::assertSame($reviewed, $after->recoveryHistory[0]['previous_candidate_revision']);
        self::assertSame($changed, $after->recoveryHistory[0]['observed_candidate_revision']);
        self::assertSame(count($historyBefore), $after->recoveryHistory[0]['history_count']);

        [$exit, $again] = $this->pipeline(['recover', 'VER-1', '--candidate', $changed, '--workspace=' . $this->run->workspace]);
        self::assertSame(0, $exit);
        self::assertSame('build', $again['current_stage']);
        self::assertCount(1, $this->state()->recoveryHistory, 'Repeating the same recovery is idempotent.');

        $failure = $this->closeFailure();
        self::assertSame('execution_review', $failure['gate']);
        self::assertStringContainsString('incomplete at stage build', $failure['detail']);
    }

    public function testAnEditAfterThePipelineCompletedBlocksCloseWithTheRecoveryCommand(): void
    {
        $gateway = new ExecutionGateway($this->run->root);
        self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace)->complete());
        $reviewed = $this->state()->candidateRevision;
        $this->copyWorkspaceSourceIntoRoot();
        self::assertNull($gateway->candidateDrift('VER-1'), 'The owner checkout holds the reviewed candidate.');
        self::assertNull($this->pendingFailure());

        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 3; }\n");
        $changed = $this->candidate($this->run->root);

        $failure = $this->closeFailure();
        self::assertSame('execution_review', $failure['gate']);
        self::assertStringContainsString('the workspace holds candidate ' . $changed . ', not the reviewed candidate ' . $reviewed, $failure['detail']);
        self::assertStringContainsString('agent-loop pipeline recover VER-1 --candidate ' . $changed, $failure['detail']);
        self::assertSame($failure['detail'], $this->pendingFailure(), 'workflow status blocks with the same reason as close.');

        [, $run] = $this->pipeline(['run', 'VER-1']);
        self::assertSame('agent-loop pipeline recover VER-1 --candidate ' . $changed, $run['next_action']);
        self::assertTrue($run['complete'], 'Routing reports the drift without reopening the pipeline.');

        [$exit, $recovered] = $this->pipeline(['recover', 'VER-1', '--candidate', $changed]);
        self::assertSame(0, $exit, json_encode($recovered, JSON_THROW_ON_ERROR));
        self::assertSame('build', $recovered['current_stage']);
        self::assertStringContainsString('incomplete at stage build', $this->closeFailure()['detail']);
    }

    public function testACompletedWorktreeRunBeforeAdoptionStaysCompleteInTheUntouchedOwnerCheckout(): void
    {
        $gateway = new ExecutionGateway($this->run->root);
        self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace)->complete());
        $state = file_get_contents((new ExecutionStateStore($this->run->root))->path('VER-1'));

        self::assertNull($gateway->candidateDrift('VER-1'), 'An owner checkout holding the base has not changed the candidate.');
        self::assertTrue($gateway->candidateAwaitingAdoption('VER-1'));
        self::assertStringContainsString('adopt the reviewed candidate from its worktree before finish', (string) $this->pendingFailure());
        self::assertStringNotContainsString('pipeline recover', (string) $this->pendingFailure());
        foreach (['status', 'run'] as $command) {
            [, $payload] = $this->pipeline([$command, 'VER-1']);
            self::assertTrue($payload['complete'], $command);
            self::assertSame('agent-loop finish VER-1', $payload['next_action'], $command);
        }
        self::assertSame($state, file_get_contents((new ExecutionStateStore($this->run->root))->path('VER-1')));

        [, $explicit] = $this->pipeline(['status', 'VER-1', '--workspace=' . $this->run->workspace]);
        self::assertSame('complete', $explicit['status'], 'The worktree still holds the reviewed candidate.');
    }

    public function testAForeignOutOfScopeEditDoesNotTurnAnUnadoptedCandidateIntoRecovery(): void
    {
        $gateway = new ExecutionGateway($this->run->root);
        self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace)->complete());
        file_put_contents($this->run->root . '/README.md', "Unrelated owner-checkout change.\n");

        self::assertNull($gateway->candidateDrift('VER-1'));
        self::assertTrue($gateway->candidateAwaitingAdoption('VER-1'));
        [, $status] = $this->pipeline(['status', 'VER-1']);
        self::assertSame('agent-loop finish VER-1', $status['next_action']);
        $failure = $this->closeFailure();
        self::assertSame('execution_review', $failure['gate']);
        self::assertStringContainsString('adopt the reviewed candidate', $failure['detail']);
    }

    public function testRevertingTheAdoptedCandidateInTheOwnerCheckoutBlocksClose(): void
    {
        $gateway = new ExecutionGateway($this->run->root);
        self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace)->complete());
        $base = (string) file_get_contents($this->run->root . '/src/Foo.php');
        $this->copyWorkspaceSourceIntoRoot();
        self::assertFalse($gateway->candidateAwaitingAdoption('VER-1'));

        file_put_contents($this->run->root . '/src/Foo.php', $base);

        self::assertTrue($gateway->candidateAwaitingAdoption('VER-1'));
        self::assertStringContainsString('still holds the base, not the reviewed candidate', $this->closeFailure()['detail']);
    }

    public function testAnInScopeEditNextToForeignOwnerCheckoutFilesNamesNoRecoveryThatWouldFail(): void
    {
        $gateway = new ExecutionGateway($this->run->root);
        self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace)->complete());
        $this->copyWorkspaceSourceIntoRoot();
        file_put_contents($this->run->root . '/README.md', "Unrelated owner-checkout change.\n");
        self::assertSame('unchanged', $gateway->observeCandidateDrift('VER-1')[0], 'Verify tolerates foreign files outside the scope.');

        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 7; }\n");

        self::assertSame(['changed_with_foreign_files', null], $gateway->observeCandidateDrift('VER-1'));
        self::assertNull($gateway->candidateDrift('VER-1'));
        $failure = $this->closeFailure();
        self::assertSame('execution_review', $failure['gate']);
        self::assertStringContainsString('also holds unrelated changes outside the scope; set those aside', $failure['detail']);
        self::assertStringNotContainsString('--candidate git-tree-v1:', $failure['detail']);
    }

    public function testARunThatResumedVerifyAfterAResolvedFailureCanStillRecoverItsRepairedCandidate(): void
    {
        $broken = new VerifyStageRunFixture();
        try {
            $broken->reachVerifyWith("<?php\nfinal class Foo { this is not php }\n");
            $gateway = new ExecutionGateway($broken->root);
            $failure = $gateway->runDeterministicStage('VER-1', 'verify', $broken->workspace);
            self::assertNotNull($failure->attention);
            $resumed = (new WorkflowHumanDecisionService($broken->root))->resolveAttention('VER-1', $failure->attention->id, 'reviewer', 'Repair the candidate.');
            self::assertSame('verify', $resumed->currentStageId);

            file_put_contents($broken->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 8; }\n");
            $repaired = $gateway->candidateDrift('VER-1', $broken->workspace);
            self::assertNotNull($repaired);

            $level = ob_get_level();
            ob_start();
            try {
                $exit = (new WorkflowPipelineCommand($broken->root))->run(['recover', 'VER-1', '--candidate', $repaired, '--workspace=' . $broken->workspace, '--format=json']);
                $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
            } finally {
                while (ob_get_level() > $level) {
                    ob_end_clean();
                }
            }
            self::assertSame(0, $exit, json_encode($payload, JSON_THROW_ON_ERROR));
            self::assertSame('build', $payload['current_stage'], 'The named recovery command must succeed.');
        } finally {
            $broken->remove();
        }
    }

    public function testAnEditAfterTheLearningDecisionNamesTheSupersedeRouteInsteadOfRecovery(): void
    {
        $gateway = new ExecutionGateway($this->run->root);
        self::assertTrue($gateway->runDeterministicStage('VER-1', 'verify', $this->run->workspace)->complete());
        $this->copyWorkspaceSourceIntoRoot();
        $this->finish([]);
        $review = (new \voku\AgentLoop\Workflow\WorkflowReviewReportReader($this->run->root))->read('VER-1');
        self::assertIsString($review['sha256']);
        $this->finish(['--reviewed-report-sha256', $review['sha256'], '--by', 'fixture-reviewer']);
        self::assertSame(0, $this->finish(['--learning', 'no_durable_learning', '--by', 'fixture-reviewer']));

        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 9; }\n");

        self::assertSame(['changed_after_learning', null], $gateway->observeCandidateDrift('VER-1'));
        self::assertNull($gateway->candidateDrift('VER-1'));
        [, $status] = $this->pipeline(['status', 'VER-1']);
        self::assertStringNotContainsString('pipeline recover', $status['next_action']);
        $failure = (string) $this->pendingFailure();
        self::assertStringContainsString('already recorded its Learning decision', $failure);
        self::assertStringContainsString('--supersede', $failure);
        self::assertStringNotContainsString('pipeline recover', $failure);
    }

    public function testRecoveryAtVerifyStaysFailClosedForOutOfScopeChanges(): void
    {
        file_put_contents($this->run->workspace . '/README.md', "Unrelated change.\n");
        $changed = $this->candidate($this->run->workspace);
        $path = (new ExecutionStateStore($this->run->root))->path('VER-1');
        $before = file_get_contents($path);

        [$exit, $payload] = $this->pipeline(['recover', 'VER-1', '--candidate', $changed, '--workspace=' . $this->run->workspace]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('outside approved Contract scope', $payload['message']);
        self::assertSame($before, file_get_contents($path));
        self::assertSame('verify', $this->state()->currentStageId);
    }

    public function testRecoveryIsStillRefusedAtAnAgentStage(): void
    {
        file_put_contents($this->run->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 2; }\n");
        $changed = $this->candidate($this->run->workspace);
        [$exit] = $this->pipeline(['recover', 'VER-1', '--candidate', $changed, '--workspace=' . $this->run->workspace]);
        self::assertSame(0, $exit);
        self::assertSame('build', $this->state()->currentStageId);

        file_put_contents($this->run->workspace . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 4; }\n");
        [$exit, $payload] = $this->pipeline(['recover', 'VER-1', '--candidate', $this->candidate($this->run->workspace), '--workspace=' . $this->run->workspace]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('RECOVERY_BLOCKED', $payload['message']);
    }

    public function testAnUnchangedWorkspaceKeepsTodaysBehaviour(): void
    {
        [, $status] = $this->pipeline(['status', 'VER-1', '--workspace=' . $this->run->workspace]);
        self::assertSame('in_progress', $status['status']);
        self::assertNull($status['workspace_candidate']);
        self::assertStringStartsWith('agent-loop pipeline run VER-1', $status['next_action']);
    }

    public function testAManualRunIsNeverRoutedToRecovery(): void
    {
        $manual = new VerifyStageRunFixture();
        try {
            $manual->enterWith(ExecutionProfileName::MANUAL);
            file_put_contents($manual->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int VALUE = 5; }\n");

            self::assertNull((new ExecutionGateway($manual->root))->candidateDrift('VER-1'));
            foreach (['status', 'run'] as $command) {
                $level = ob_get_level();
                ob_start();
                try {
                    (new WorkflowPipelineCommand($manual->root))->run([$command, 'VER-1', '--format=json']);
                    $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
                } finally {
                    while (ob_get_level() > $level) {
                        ob_end_clean();
                    }
                }
                self::assertTrue($payload['complete'], $command);
                self::assertSame('agent-loop finish VER-1', $payload['next_action'], $command);
            }
        } finally {
            $manual->remove();
        }
    }

    /** @return array{gate: string, detail: string} */
    private function closeFailure(): array
    {
        $contract = (new TaskContractStore($this->run->root))->load('VER-1');
        $run = (new GovernedRunStore($this->run->root))->findForContract($contract);
        self::assertNotNull($run);
        $session = (new SessionStore())->activeForTask((new ProjectLayout($this->run->root))->sessionsRoot(), 'VER-1');
        self::assertNotNull($session);
        $failure = (new WorkflowCloseReadinessInspector($this->run->root))->inspect($contract, $run, $session)->firstFailure();
        self::assertNotNull($failure);

        return $failure;
    }

    /** @param list<string> $options */
    private function finish(array $options): int
    {
        $level = ob_get_level();
        ob_start();
        try {
            return (new \voku\AgentLoop\Workflow\HostFrontDoorApplication($this->run->root))->run('finish', ['VER-1', '--format=json', ...$options]);
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
    }

    private function pendingFailure(): ?string
    {
        $contract = (new TaskContractStore($this->run->root))->load('VER-1');
        $run = (new GovernedRunStore($this->run->root))->findForContract($contract);
        self::assertNotNull($run);

        return (new WorkflowCloseReadinessInspector($this->run->root))->pendingRecoveredExecutionFailure($contract, $run);
    }

    private function copyWorkspaceSourceIntoRoot(): void
    {
        $source = file_get_contents($this->run->workspace . '/src/Foo.php');
        self::assertIsString($source);
        self::assertNotFalse(file_put_contents($this->run->root . '/src/Foo.php', $source));
    }

    private function candidate(string $workspace): string
    {
        $plan = (new ExecutionPlanStore($this->run->root))->load('VER-1');
        if ($plan->baseCommit === null) {
            throw new RuntimeException('Fixture execution plan has no governed base.');
        }
        $stateRoot = realpath((new ProjectLayout($this->run->root))->stateRoot());
        $root = realpath($this->run->root);
        $relative = is_string($stateRoot) && is_string($root) && str_starts_with($stateRoot, $root . '/') ? substr($stateRoot, strlen($root) + 1) : null;

        return (new \voku\AgentLoop\Execution\CandidateWorkspaceIdentity())->observe($workspace, $plan->baseCommit, $relative);
    }

    private function state(): ExecutionState
    {
        $state = (new ExecutionStateStore($this->run->root))->find('VER-1');
        self::assertInstanceOf(ExecutionState::class, $state);

        return $state;
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: array<string, mixed>}
     */
    private function pipeline(array $arguments): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = (new WorkflowPipelineCommand($this->run->root))->run([...$arguments, '--format=json']);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exit, json_decode($output, true, 512, JSON_THROW_ON_ERROR)];
    }
}
