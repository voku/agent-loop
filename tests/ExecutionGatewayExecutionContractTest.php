<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoop\Execution\AttentionKind;
use voku\AgentLoop\Execution\AttentionRequest;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\ExecutionContractStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowContextCommand;
use voku\AgentLoop\Workflow\WorkflowExecutionProfileCommand;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoop\Workflow\WorkflowRejectCommand;
use voku\AgentLoop\Workflow\WorkflowRetireCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentRecallCompiler\CompileRequest;
use voku\AgentRecallCompiler\CompileResult;

final class ExecutionGatewayExecutionContractTest extends TestCase
{
    private const string TASK = 'ABC-123';
    private const string BASE_COMMIT = '1111111111111111111111111111111111111111';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-gateway-l1-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/operating-prompts.json', json_encode([
            'schema_version' => '1.0',
            'prompts' => [[
                'id' => 'test-l2',
                'level' => 2,
                'template' => 'Create a project-specific L1 execution contract.',
            ]],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testL2AgentStageFailsClosedUntilExecutionContractIsReady(): void
    {
        $this->prepareSurgicalRun(l2: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXECUTION_CONTRACT_NOT_READY');
        $this->expectExceptionMessage('state is missing');

        (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');
    }

    public function testReadyL2ContractBecomesExecutorPromptAndCarriesExactSource(): void
    {
        $this->prepareSurgicalRun(l2: true);
        $store = new ExecutionContractStore($this->root);
        $store->writeReady(self::TASK, 'constructor', $this->l1Contract());

        $bundle = (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');

        self::assertNotNull($bundle->executionContractSource);
        self::assertSame(
            '.agent-loop/recall/' . self::TASK . '/execution-contract.md',
            $bundle->executionContractSource['path'],
        );
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $bundle->executionContractSource['sha256']);
        self::assertStringContainsString('# Governed execution contract', $bundle->prompt);
        self::assertStringContainsString('Inspect the exact current owner boundary before changing code.', $bundle->prompt);
        self::assertStringNotContainsString('# Governed Recall', $bundle->prompt);
        self::assertStringNotContainsString('L2 Operational Prompt Construction', $bundle->prompt);
        self::assertStringNotContainsString('Create a project-specific L1 execution contract.', $bundle->prompt);
    }

    public function testStaleL2ContractFailsClosedAtStagePreparation(): void
    {
        $this->prepareSurgicalRun(l2: true);
        $store = new ExecutionContractStore($this->root);
        $store->writeReady(self::TASK, 'constructor', $this->l1Contract());

        $factsPath = $this->root . '/.agent-loop/recall/' . self::TASK . '/facts.json';
        $facts = json_decode((string) file_get_contents($factsPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($facts);
        $facts['bundle_sha256'] = str_repeat('b', 64);
        file_put_contents($factsPath, json_encode($facts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('EXECUTION_CONTRACT_NOT_READY');
        $this->expectExceptionMessage('state is stale');

        (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');
    }

    public function testNoL2TaskKeepsExistingRecallProjection(): void
    {
        $this->prepareSurgicalRun(l2: false);

        $bundle = (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');

        self::assertNull($bundle->executionContractSource);
        self::assertStringContainsString('# Governed Recall reference data', $bundle->prompt);
        self::assertStringContainsString('untrusted project data', $bundle->prompt);
        self::assertStringContainsString('Stay inside the approved Contract.', $bundle->prompt);
        self::assertStringNotContainsString('# Governed execution contract', $bundle->prompt);
    }

    public function testFallbackRecallRejectsSymbolicLinkToExternalFile(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $recallPath = $this->root . '/.agent-loop/recall/' . self::TASK . '/system.md';
        $externalPath = sys_get_temp_dir() . '/agent-loop-recall-secret-' . bin2hex(random_bytes(5)) . '.md';
        file_put_contents($externalPath, 'private value from outside the project');
        unlink($recallPath);
        self::assertTrue(symlink($externalPath, $recallPath));

        try {
            (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');
            self::fail('A symbolic-link Recall source must not be read into the agent prompt.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            if (is_link($recallPath)) {
                unlink($recallPath);
            }
            if (is_file($externalPath)) {
                unlink($externalPath);
            }
        }
    }

    public function testFallbackRecallRejectsSymbolicLinkAtRecallRoot(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $recallRoot = $this->root . '/.agent-loop/recall';
        $externalRoot = sys_get_temp_dir() . '/agent-loop-recall-root-' . bin2hex(random_bytes(5));
        self::assertTrue(rename($recallRoot, $externalRoot));
        self::assertTrue(symlink($externalRoot, $recallRoot));

        try {
            (new ExecutionGateway($this->root))->prepareStage(self::TASK, 'investigate');
            self::fail('A symlinked Recall root must not be read into the agent prompt.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('symbolic link', $exception->getMessage());
        } finally {
            if (is_link($recallRoot)) {
                unlink($recallRoot);
            }
            if (is_dir($externalRoot)) {
                $this->rm($externalRoot);
            }
        }
    }

    public function testReadyL2ContractIsProjectedThroughOrdinaryEnterContext(): void
    {
        $this->prepareSurgicalRun(l2: true);
        (new ExecutionContractStore($this->root))->writeReady(self::TASK, 'constructor', $this->l1Contract());

        $payload = $this->enterPayload();
        $context = implode("\n", $payload['context']['lines']);

        self::assertTrue($payload['mutation_ready']);
        self::assertSame('host_work', $payload['next_action_kind']);
        self::assertStringContainsString('Governed execution contract:', $context);
        self::assertStringContainsString('.agent-loop/recall/' . self::TASK . '/execution-contract.md', $context);
        self::assertStringContainsString('## Goal', $context);
        self::assertStringContainsString('Inspect the exact current owner boundary before changing code.', $context);
        self::assertStringNotContainsString('L2 Operational Prompt Construction', $context);
        self::assertStringNotContainsString('Create a project-specific L1 execution contract.', $context);
    }

    public function testNoL2EnterContextDoesNotInventExecutionContract(): void
    {
        $this->prepareSurgicalRun(l2: false);

        $payload = $this->enterPayload();
        $context = implode("\n", $payload['context']['lines']);

        self::assertTrue($payload['mutation_ready']);
        self::assertSame('host_work', $payload['next_action_kind']);
        self::assertStringNotContainsString('Governed execution contract:', $context);
    }

    public function testExecutionContractCannotBeSilentlyTruncatedByContextBudget(): void
    {
        $this->prepareSurgicalRun(l2: true);
        (new ExecutionContractStore($this->root))->writeReady(self::TASK, 'constructor', $this->largeL1Contract());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('refusing to present a partial L1');

        (new WorkflowContextCommand($this->root))->build(self::TASK, 12, 512);
    }

    public function testExecutionBindingIsNullWithoutContract(): void
    {
        self::assertNull((new ExecutionGateway($this->root))->observeCurrentExecutionBinding(self::TASK));
    }

    public function testExecutionBindingDoesNotPrepareMissingPlanOrState(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $planPath = (new ExecutionPlanStore($this->root))->path(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        if (is_file($statePath)) {
            self::assertTrue(unlink($statePath));
        }
        if (is_file($planPath)) {
            self::assertTrue(unlink($planPath));
        }

        self::assertNull((new ExecutionGateway($this->root))->observeCurrentExecutionBinding(self::TASK));
        self::assertFileDoesNotExist($planPath);
        self::assertFileDoesNotExist($statePath);
    }

    public function testExecutionBindingProjectsCurrentRunRevisionAndPlanDigestWithoutRewriting(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $planPath = (new ExecutionPlanStore($this->root))->path(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        $planBefore = file_get_contents($planPath);
        $stateBefore = file_get_contents($statePath);
        $plan = (new ExecutionPlanStore($this->root))->load(self::TASK);
        $contract = (new TaskContractStore($this->root))->load(self::TASK);
        $run = (new GovernedRunStore($this->root))->findForContract($contract);
        self::assertNotNull($run);

        $binding = $gateway->observeCurrentExecutionBinding(self::TASK);

        self::assertNotNull($binding);
        self::assertSame($run->runId, $binding->runId);
        self::assertSame($contract->revision, $binding->contractRevision);
        self::assertSame($plan->digest(), $binding->executionPlanDigest);
        self::assertSame($planBefore, file_get_contents($planPath));
        self::assertSame($stateBefore, file_get_contents($statePath));
    }

    public function testExecutionBindingIsNullForRetiredRun(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        self::assertNotNull($gateway->observeCurrentExecutionBinding(self::TASK));
        ob_start();
        self::assertSame(0, (new WorkflowRetireCommand($this->root))->run([
            self::TASK, '--status', 'abandoned', '--reason', 'Dropped by owner.', '--by', 'lars',
        ]));
        ob_end_clean();

        self::assertNull($gateway->observeCurrentExecutionBinding(self::TASK));
    }

    public function testExecutionBindingIsNullWhileSupersedingRevisionIsUnapprovedOrNotEntered(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();
        self::assertNull($gateway->observeCurrentExecutionBinding(self::TASK), 'candidate revision');

        ob_start();
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run([self::TASK, '--revision', '2', '--by', 'lars']));
        ob_end_clean();
        // The Run id of revision 1 is still in the manifest until the next
        // prepare; it must not be reported as the current execution.
        self::assertNull($gateway->observeCurrentExecutionBinding(self::TASK), 'approved but not entered');
    }

    public function testExecutionBindingDoesNotHideCorruptContractAsNoExecution(): void
    {
        $this->prepareSurgicalRun(l2: false);
        self::assertNotFalse(file_put_contents((new TaskContractStore($this->root))->path(self::TASK), '{broken JSON'));

        $this->expectException(RuntimeException::class);
        (new ExecutionGateway($this->root))->observeCurrentExecutionBinding(self::TASK);
    }

    public function testExecutionBindingRefusesTamperedPlan(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->rewriteJson((new ExecutionPlanStore($this->root))->path(self::TASK), 'run_id', 'run:other');

        $this->expectException(RuntimeException::class);
        $gateway->observeCurrentExecutionBinding(self::TASK);
    }

    public function testReadOnlyAttentionDoesNotPrepareMissingPlanOrState(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $planPath = (new ExecutionPlanStore($this->root))->path(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);

        if (is_file($statePath)) {
            self::assertTrue(unlink($statePath));
        }
        if (is_file($planPath)) {
            self::assertTrue(unlink($planPath));
        }

        self::assertNull((new ExecutionGateway($this->root))->observePendingAttention(self::TASK));
        self::assertFileDoesNotExist($planPath);
        self::assertFileDoesNotExist($statePath);
    }

    public function testReadOnlyAttentionDoesNotHideCorruptContractAsMissingState(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $planPath = (new ExecutionPlanStore($this->root))->path(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        if (is_file($statePath)) {
            self::assertTrue(unlink($statePath));
        }
        if (is_file($planPath)) {
            self::assertTrue(unlink($planPath));
        }

        $contractPath = (new TaskContractStore($this->root))->path(self::TASK);
        self::assertNotFalse(file_put_contents($contractPath, '{broken JSON'));

        try {
            (new ExecutionGateway($this->root))->observePendingAttention(self::TASK);
            self::fail('Corrupt Contract must never be classified as no Attention.');
        } catch (RuntimeException) {
            self::assertFileDoesNotExist($planPath);
            self::assertFileDoesNotExist($statePath);
        }
    }

    public function testReadOnlyAttentionPreservesExistingPlanAndStateBytes(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $planPath = (new ExecutionPlanStore($this->root))->path(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        $planBefore = file_get_contents($planPath);
        $stateBefore = file_get_contents($statePath);
        self::assertIsString($planBefore);
        self::assertIsString($stateBefore);

        self::assertNull($gateway->observePendingAttention(self::TASK));
        self::assertSame($planBefore, file_get_contents($planPath));
        self::assertSame($stateBefore, file_get_contents($statePath));
    }

    public function testReadOnlyAttentionReturnsCurrentBoundAttentionWithoutRewritingState(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsString($state['run_id'] ?? null);

        $attention = new AttentionRequest(
            'attention:fixture',
            self::TASK,
            $state['run_id'],
            AttentionKind::CLARIFICATION_REQUIRED,
            'Owner needs clarification.',
            'investigate',
            '2026-11-13T20:00:00Z',
        );
        $state['attention'] = $attention->toArray();
        self::assertNotFalse(file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR)));
        $before = file_get_contents($statePath);

        $observed = $gateway->observePendingAttention(self::TASK);
        self::assertNotNull($observed);
        self::assertSame($attention->toArray(), $observed->toArray());
        self::assertSame($before, file_get_contents($statePath));
    }

    #[DataProvider('mismatchedAttentionIdentityProvider')]
    public function testReadOnlyAttentionRejectsMismatchedNestedIdentity(string $field, string $value): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsString($state['run_id'] ?? null);

        $attention = new AttentionRequest(
            'attention:fixture',
            self::TASK,
            $state['run_id'],
            AttentionKind::CLARIFICATION_REQUIRED,
            'Owner needs clarification.',
            'investigate',
            '2026-11-13T20:00:00Z',
        );
        $state['attention'] = $attention->toArray();
        $state['attention'][$field] = $value;
        self::assertNotFalse(file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR)));
        $before = file_get_contents($statePath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention identity does not match');
        try {
            $gateway->observePendingAttention(self::TASK);
        } finally {
            self::assertSame($before, file_get_contents($statePath));
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function mismatchedAttentionIdentityProvider(): iterable
    {
        yield 'task identity' => ['task_id', 'OTHER-123'];
        yield 'run identity' => ['run_id', 'run:other'];
    }

    public function testReadOnlyAttentionRefusesStaleExecutionPlanBinding(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        $data = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        $data['execution_plan_digest'] = 'sha256:' . str_repeat('0', 64);
        self::assertNotFalse(file_put_contents($statePath, json_encode($data, JSON_THROW_ON_ERROR)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention state is stale');
        $gateway->observePendingAttention(self::TASK);
    }

    public function testReadOnlyAttentionObservesNullForRetiredRunWithLeftoverState(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        // Retirement is allowed while Attention is pending; that Attention
        // belongs to a Run nobody can continue and must not surface as current.
        $state = json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        self::assertIsString($state['run_id'] ?? null);
        $state['attention'] = (new AttentionRequest(
            'attention:retired',
            self::TASK,
            $state['run_id'],
            AttentionKind::CLARIFICATION_REQUIRED,
            'Owner needs clarification.',
            'investigate',
            '2026-11-13T20:00:00Z',
        ))->toArray();
        self::assertNotFalse(file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR)));
        self::assertNotNull($gateway->observePendingAttention(self::TASK));

        ob_start();
        self::assertSame(0, (new WorkflowRetireCommand($this->root))->run([
            self::TASK, '--status', 'abandoned', '--reason', 'Dropped by owner.', '--by', 'lars',
        ]));
        ob_end_clean();
        $before = file_get_contents($statePath);
        self::assertIsString($before);

        self::assertNull($gateway->observePendingAttention(self::TASK));
        self::assertSame($before, file_get_contents($statePath));
    }

    public function testReadOnlyAttentionObservesNullForSupersedingCandidateRevision(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();

        self::assertNull($gateway->observePendingAttention(self::TASK));
    }

    public function testReadOnlyAttentionObservesNullForRejectedSupersedingRevision(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();
        ob_start();
        self::assertSame(0, (new WorkflowRejectCommand($this->root))->run([
            self::TASK, '--revision', '2', '--by', 'lars', '--reason', 'Not this way.',
        ]));
        ob_end_clean();

        self::assertNull($gateway->observePendingAttention(self::TASK));
    }

    public function testReadOnlyAttentionObservesNullForApprovedRevisionNotYetEntered(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();
        ob_start();
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run([self::TASK, '--revision', '2', '--by', 'lars']));
        ob_end_clean();
        $statePath = (new ExecutionStateStore($this->root))->path(self::TASK);
        $before = file_get_contents($statePath);

        self::assertNull($gateway->observePendingAttention(self::TASK));
        self::assertSame($before, file_get_contents($statePath));
    }

    public function testReadOnlyAttentionRejectsStateClaimingNewerContractRevision(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->rewriteJson((new ExecutionStateStore($this->root))->path(self::TASK), 'contract_revision', 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention state is stale');
        $gateway->observePendingAttention(self::TASK);
    }

    #[DataProvider('driftedPlanBindingProvider')]
    public function testReadOnlyAttentionRejectsDriftedPlanBinding(string $field, int|string $value, string $message): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->rewriteJson((new ExecutionPlanStore($this->root))->path(self::TASK), $field, $value);

        // The plan store verifies its own content digest, so tampered binding
        // fields are refused before the gateway's binding comparison.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $gateway->observePendingAttention(self::TASK);
    }

    public function testReadOnlyAttentionRejectsCurrentRevisionStateWithoutRun(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        self::assertTrue(unlink((new GovernedRunStore($this->root))->path(self::TASK)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention state is not bound to a current governed Run.');
        $gateway->observePendingAttention(self::TASK);
    }

    #[DataProvider('unarchivedRevisionBumpProvider')]
    public function testReadOnlyAttentionRejectsRevisionBumpWithoutContractHistory(string $status, string $message): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $contractPath = (new TaskContractStore($this->root))->path(self::TASK);
        $this->rewriteJson($contractPath, 'revision', 2);
        if ($status === 'candidate') {
            $this->rewriteJson($contractPath, 'status', 'candidate');
            $this->rewriteJson($contractPath, 'approved_at', null);
            $this->rewriteJson($contractPath, 'approved_by', null);
        }
        self::assertSame([], (new TaskContractStore($this->root))->supersededRevisions(self::TASK));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        $gateway->observePendingAttention(self::TASK);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unarchivedRevisionBumpProvider(): iterable
    {
        yield 'approved' => ['approved', 'Pending Attention state is not bound to a current governed Run.'];
        yield 'candidate' => ['candidate', 'Pending Attention state exists without a current approved Contract.'];
    }

    public function testReadOnlyAttentionRejectsStateWithoutContract(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        self::assertTrue(unlink((new TaskContractStore($this->root))->path(self::TASK)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention state exists without a Contract.');
        $gateway->observePendingAttention(self::TASK);
    }

    public function testReadOnlyAttentionRejectsSameRevisionContractTampering(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->rewriteJson((new TaskContractStore($this->root))->path(self::TASK), 'goal', 'Tampered goal.');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention state is not bound to a current governed Run.');
        $gateway->observePendingAttention(self::TASK);
    }

    public function testReadOnlyAttentionRejectsSameRevisionContractThatIsNoLongerApproved(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $contractPath = (new TaskContractStore($this->root))->path(self::TASK);
        $this->rewriteJson($contractPath, 'status', 'candidate');
        $this->rewriteJson($contractPath, 'approved_at', null);
        $this->rewriteJson($contractPath, 'approved_by', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention state exists without a current approved Contract.');
        $gateway->observePendingAttention(self::TASK);
    }

    public function testReadOnlyAttentionRejectsNewerRevisionStateAgainstNonApprovedContract(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();
        $this->rewriteJson((new ExecutionStateStore($this->root))->path(self::TASK), 'contract_revision', 3);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Pending Attention state exists without a current approved Contract.');
        $gateway->observePendingAttention(self::TASK);
    }

    /** @return iterable<string, array{string, int|string, string}> */
    public static function driftedPlanBindingProvider(): iterable
    {
        yield 'run identity' => ['run_id', 'run:other', 'Execution plan digest does not match persisted content'];
        yield 'contract revision' => ['contract_revision', 7, 'Execution plan digest does not match persisted content'];
        yield 'contract source' => ['contract_source', 'sha256:' . str_repeat('d', 64), 'Execution plan contract_source must be an object.'];
    }

    public function testReadOnlyAttentionRefusesTamperedHistoryClaimingTheCurrentRevision(): void
    {
        // Pins the strict "older than current" guard: a state at the current
        // revision is never proven superseded, even if history claims it.
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();
        $this->rewriteJson((new ExecutionStateStore($this->root))->path(self::TASK), 'contract_revision', 2);
        $this->rewriteJson($this->archivedContractPath(1), 'revision', 2);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a current approved Contract');
        $gateway->observePendingAttention(self::TASK);
    }

    public function testReadOnlyAttentionRequiresExactArchivedRevisionForSupersession(): void
    {
        // Pins the exact revision match: an archive of a different (older)
        // revision does not prove that the state's revision was superseded.
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();
        $this->supersedeContract();
        self::assertSame(3, (new TaskContractStore($this->root))->load(self::TASK)->revision, 'Precondition: two supersessions.');
        $this->rewriteJson((new ExecutionStateStore($this->root))->path(self::TASK), 'contract_revision', 2);
        self::assertTrue(unlink($this->archivedContractPath(2)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a current approved Contract');
        $gateway->observePendingAttention(self::TASK);
    }

    public function testRetiredRunIsNotValidatedFurtherByReadOnlyObservations(): void
    {
        // Decision (#1061): a retired Run can carry no actionable Attention and
        // is not current execution, so its leftover plan is not inspected and
        // corruption there is not surfaced as an unavailable observation.
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        ob_start();
        self::assertSame(0, (new WorkflowRetireCommand($this->root))->run([
            self::TASK, '--status', 'abandoned', '--reason', 'Dropped by owner.', '--by', 'lars',
        ]));
        ob_end_clean();
        self::assertNotFalse(file_put_contents((new ExecutionPlanStore($this->root))->path(self::TASK), '{broken JSON'));

        self::assertNull($gateway->observePendingAttention(self::TASK));
        self::assertNull($gateway->observeCurrentExecutionBinding(self::TASK));
    }

    public function testReadOnlyAttentionFailsClosedOnUnreadableContractHistory(): void
    {
        // Decision (#1061): supersession is only proven by readable owner
        // history. One corrupt archive makes the observation unavailable
        // instead of guessing which revision it held.
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        $this->supersedeContract();
        self::assertNull($gateway->observePendingAttention(self::TASK), 'Precondition: readable history proves supersession.');
        self::assertNotFalse(file_put_contents($this->archivedContractPath(1), '{broken JSON'));

        $this->expectException(RuntimeException::class);
        $gateway->observePendingAttention(self::TASK);
    }

    public function testReadOnlyAttentionRejectsStateWithoutPlan(): void
    {
        $this->prepareSurgicalRun(l2: false);
        $gateway = new ExecutionGateway($this->root);
        $gateway->projection(self::TASK);
        self::assertTrue(unlink((new ExecutionPlanStore($this->root))->path(self::TASK)));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/execution plan/i');
        $gateway->observePendingAttention(self::TASK);
    }

    private function supersedeContract(): void
    {
        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run([
            self::TASK,
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Revised goal after review.',
            '--validation', 'vendor/bin/phpunit',
            '--base-commit', self::BASE_COMMIT,
            '--supersede',
        ]));
        ob_end_clean();
    }

    private function archivedContractPath(int $revision): string
    {
        $path = (new \voku\AgentLoop\ProjectLayout($this->root))->contractHistoryFile(self::TASK, sprintf('contract.%03d.json', $revision));
        self::assertFileExists($path);

        return $path;
    }

    private function rewriteJson(string $path, string $field, int|string|null $value): void
    {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertArrayHasKey($field, $data);
        $data[$field] = $value;
        self::assertNotFalse(file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR)));
    }

    private function prepareSurgicalRun(bool $l2): void
    {
        $plan = [
            self::TASK,
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Prove the executor consumes the current governed contract.',
            '--validation', 'vendor/bin/phpunit',
            '--base-commit', self::BASE_COMMIT,
        ];
        if ($l2) {
            $plan[] = '--operating-prompt-manifest';
            $plan[] = 'operating-prompts.json';
            $plan[] = '--operating-prompt';
            $plan[] = '{"id":"test-l2","arguments":{}}';
        }

        ob_start();
        self::assertSame(0, (new WorkflowPlanCommand($this->root))->run($plan));
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run([self::TASK, '--revision', '1', '--by', 'lars']));
        self::assertSame(0, (new WorkflowExecutionProfileCommand($this->root))->run([
            self::TASK, '--profile', 'surgical', '--by', 'lars',
        ]));
        ob_end_clean();

        ob_start();
        $exit = (new HostFrontDoorCommand(
            $this->root,
            recallCompiler: function (CompileRequest $request) use ($l2): CompileResult {
                $directory = $this->root . '/.agent-loop/recall/' . self::TASK;
                if (!is_dir($directory)) {
                    mkdir($directory, 0o775, true);
                }
                file_put_contents($directory . '/meta.json', json_encode([
                    'schema_version' => '1.0',
                    'task_id' => self::TASK,
                    'compilation_id' => self::TASK . '-execution-contract-test',
                    'selected_guidance' => [],
                    'selected_constraints' => [],
                    'output_hashes' => [],
                ], JSON_THROW_ON_ERROR));
                file_put_contents(
                    $directory . '/system.md',
                    $l2
                        ? "# Governed recall\n## L2 Operational Prompt Construction\nCreate a project-specific L1 execution contract.\n"
                        : "# Governed recall\nStay inside the approved Contract.\n",
                );
                if ($l2) {
                    file_put_contents($directory . '/facts.json', json_encode([
                        'schema_version' => '1.0',
                        'bundle_sha256' => str_repeat('a', 64),
                        'facts' => [[
                            'id' => 'operating-prompt.test-l2',
                            'type' => 'operating_prompt',
                            'authority' => 'approved_contract',
                            'source_ref' => 'operating-prompts.json#test-l2',
                            'scope' => ['src/Foo.php'],
                            'payload' => [
                                'prompt_id' => 'test-l2',
                                'level' => 2,
                                'arguments' => [],
                                'content' => 'Create a project-specific L1 execution contract.',
                                'template_sha256' => str_repeat('c', 64),
                            ],
                        ]],
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
                }

                return new CompileResult(
                    $request->outputDirectory,
                    self::TASK . '-execution-contract-test',
                    str_repeat('a', 64),
                );
            },
        ))->run('enter', [self::TASK, '--format=json']);
        ob_end_clean();

        self::assertSame($l2 ? 1 : 0, $exit);
    }

    /** @return array<string, mixed> */
    private function enterPayload(): array
    {
        ob_start();
        $exit = (new HostFrontDoorCommand($this->root))->run('enter', [self::TASK, '--format=json']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return $payload;
    }

    private function l1Contract(): string
    {
        return <<<'MD'
## Goal
Execute the currently approved bounded task.

## Context
Inspect the exact current owner boundary before changing code.

## Constraints
Stay inside src/Foo.php and preserve workflow authority.

## Verification
Run `vendor/bin/phpunit` and inspect the resulting diff.

## Done When
The bounded task is implemented and the declared verification passes.
MD;
    }

    private function largeL1Contract(): string
    {
        return sprintf(
            <<<'MD'
## Goal
Execute the currently approved bounded task.

## Context
%s

## Constraints
Stay inside src/Foo.php and preserve workflow authority.

## Verification
Run `vendor/bin/phpunit` and inspect the resulting diff.

## Done When
The bounded task is implemented and the declared verification passes.
MD,
            str_repeat('Current owner evidence must remain exact and visible. ', 40),
        );
    }

    private function rm(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $directories = [$path];
        for ($index = 0; $index < count($directories); ++$index) {
            foreach (new FilesystemIterator($directories[$index], FilesystemIterator::SKIP_DOTS) as $item) {
                if (!$item instanceof SplFileInfo) {
                    throw new RuntimeException('FilesystemIterator returned an unexpected entry type.');
                }
                if ($item->isDir() && !$item->isLink()) {
                    $directories[] = $item->getPathname();
                    continue;
                }
                unlink($item->getPathname());
            }
        }

        foreach (array_reverse($directories) as $directory) {
            rmdir($directory);
        }
    }
}
