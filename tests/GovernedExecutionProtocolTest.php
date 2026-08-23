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
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageOutcome;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class GovernedExecutionProtocolTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-execution-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testManualProfileRemainsDefaultAndCompletesWithoutRunnerStages(): void
    {
        $this->prepareApprovedRun();

        $plan = (new ExecutionPlanStore($this->root))->load('ABC-123');
        self::assertSame(ExecutionProfileName::MANUAL, $plan->profile);
        self::assertSame([], $plan->stages);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $plan->digest());

        $projection = (new ExecutionGateway($this->root))->projection('ABC-123');
        self::assertTrue($projection->complete());
        self::assertNull($projection->currentStageId);
        self::assertSame(0, $projection->currentAttempt);
        self::assertFileExists((new ExecutionStateStore($this->root))->path('ABC-123'));
    }

    public function testSurgicalProfileIsResolvedBeforeRunAndAdvancesOnlyThroughAcceptedResults(): void
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

        $bundle = $gateway->prepareStage('ABC-123', 'investigate');
        self::assertFalse($bundle->mayMutate);
        self::assertSame('investigator', $bundle->roleId);
        self::assertContains(StageOutcome::COMPLETED, $bundle->acceptedOutcomes);
        self::assertSame(['src/Foo.php'], $bundle->allowedScope);
        self::assertStringContainsString('A successful process exit is not workflow approval.', $bundle->prompt);

        $result = new StageResult(
            'submission:investigate:1',
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            StageOutcome::COMPLETED,
            'candidate:one',
            ['artifact:investigation'],
            [],
            'Investigation complete.',
        );
        $after = $gateway->submitStageResult($result);
        self::assertSame('build', $after->currentStageId);
        self::assertSame(1, $after->currentAttempt);
        self::assertCount(1, $after->handoffs);
        self::assertSame('investigate', $after->handoffs[0]->fromStage);
        self::assertSame('build', $after->handoffs[0]->toStage);

        $duplicate = $gateway->submitStageResult($result);
        self::assertSame('build', $duplicate->currentStageId);
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
            'candidate:stale',
            [],
            [],
            'Stale candidate.',
        ));
    }

    public function testClarificationCreatesAttentionAndResumeCreatesANewAttempt(): void
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
            'candidate:clarify',
            [],
            [],
            'Which compatibility boundary is authoritative?',
        ));
        self::assertNotNull($waiting->attention);
        self::assertSame('investigate', $waiting->currentStageId);
        self::assertSame(1, $waiting->currentAttempt);

        $attention = $waiting->attention;
        $resumed = $gateway->resolveAttention('ABC-123', $attention->id);
        self::assertNull($resumed->attention);
        self::assertSame('investigate', $resumed->currentStageId);
        self::assertSame(2, $resumed->currentAttempt);
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

    private function prepareSurgicalRun(): void
    {
        $this->planAndApprove();
        ob_start();
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            'ABC-123', '--profile', 'surgical', '--by', 'lars',
        ]));
        ob_end_clean();
        $this->enter();
    }

    private function planAndApprove(): void
    {
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            'ABC-123',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Prove governed external stage execution.',
            '--validation', 'vendor/bin/phpunit',
        ]));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--by', 'lars']));
        ob_end_clean();
        self::assertSame('approved', (new TaskContractStore($this->root))->load('ABC-123')->status);
    }

    private function enter(): void
    {
        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            function (array $argv): int {
                $directory = $this->root . '/.agent-loop/recall/ABC-123';
                if (!is_dir($directory)) {
                    mkdir($directory, 0o775, true);
                }
                file_put_contents($directory . '/meta.json', json_encode([
                    'schema_version' => '1.0',
                    'task_id' => 'ABC-123',
                    'compilation_id' => 'ABC-123-execution-test',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents($directory . '/system.md', "# Governed recall\nStay inside the approved Contract.\n");

                return 0;
            },
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
