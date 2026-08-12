<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCloseCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowCloseCommandTest extends TestCase
{
    private string $root;
    private string $sessionId;
    private string $runId;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-close-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
        mkdir($this->root . '/learning-root', 0o775, true);
        $this->prepareGovernedRun();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCloseFailsWhenRecallMetaIsMissing(): void
    {
        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());
        self::assertFileDoesNotExist($this->verificationReceipt());
        self::assertStringContainsString('[FAIL] recall: missing', $result['output']);
    }

    public function testCloseFailsWhenReviewReportIsMissing(): void
    {
        $this->writeRecallMeta();

        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());
        self::assertStringContainsString('[FAIL] review: missing', $result['output']);
    }

    public function testCloseFailsWhenReviewReportStatusIsFail(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'fail']);

        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());
        self::assertStringContainsString('status is fail', $result['output']);
    }

    public function testCloseFailsWhenCrossPackageVerifierFails(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'ok']);
        $this->breakVerifierForTask();

        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());
        self::assertFileDoesNotExist($this->verificationReceipt());
    }

    public function testCloseRequiresOutcomeForEverySelectedGuidanceItem(): void
    {
        $this->writeRecallMeta([
            'task_id' => 'ABC-123',
            'compilation_id' => 'compilation.abc.001',
            'selected_guidance' => ['G-001'],
            'selected_constraints' => [],
            'output_hashes' => [],
        ]);
        $this->writeReviewReport(['status' => 'ok']);

        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('session was not closed', $result['output']);
        self::assertFileDoesNotExist($this->verificationReceipt());
    }

    public function testCloseRejectsRunBoundToSupersededContractRevision(): void
    {
        $contracts = new TaskContractStore($this->root);
        $current = $contracts->load('ABC-123');
        $contracts->revise(
            'ABC-123',
            $current->goal . ' revised',
            $current->scope,
            $current->nonGoals,
            $current->validation,
            'lars',
        );
        $contracts->approve('ABC-123', 'lars');

        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());
        self::assertStringContainsString('not bound to the current Task Contract revision', $result['output']);
    }

    public function testClosePersistsVerificationBeforeClosingDisposableSession(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'ok']);

        $result = $this->runClose();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(SessionStatus::DONE, $this->sessionStatus());
        self::assertFileExists($this->verificationReceipt());
        self::assertStringContainsString('durable verification receipt persisted', $result['output']);
        self::assertStringContainsString('disposable Session marked done', $result['output']);

        $receipt = json_decode((string) file_get_contents($this->verificationReceipt()), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($this->runId, $receipt['run_id'] ?? null);
        self::assertSame(1, $receipt['contract_revision'] ?? null);
        self::assertSame('satisfied', $receipt['verdict'] ?? null);
        self::assertSame('vendor/bin/phpunit', $receipt['obligations'][0]['command'] ?? null);
        self::assertSame('passed', $receipt['obligations'][0]['status'] ?? null);
    }

    public function testCloseAcceptsWarnReviewStatus(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'warn']);

        $result = $this->runClose();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(SessionStatus::DONE, $this->sessionStatus());
        self::assertFileExists($this->verificationReceipt());
    }

    public function testAcceptedRiskCanCloseAfterPostContractGateFailure(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'ok']);
        $this->breakVerifierForTask();

        $result = $this->runClose([
            'ABC-123', '--status', 'done',
            '--accept-risk', 'Manual review.',
            '--accept-risk-by', 'lars',
            '--learning-root', $this->root . '/learning-root',
        ]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(SessionStatus::DONE, $this->sessionStatus());
        self::assertFileExists($this->root . '/.agent-loop/risks/ABC-123.accepted-risk.md');
        self::assertFileExists($this->verificationReceipt());
    }

    public function testAcceptRiskWithoutNamedOwnerIsRefused(): void
    {
        $result = $this->runClose([
            'ABC-123', '--status', 'done',
            '--accept-risk', 'Manual review.',
            '--learning-root', $this->root . '/learning-root',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());
        self::assertStringContainsString('--accept-risk-by', $result['output']);
        self::assertFileDoesNotExist($this->verificationReceipt());
    }

    public function testEditBundleRequiresPassingVerificationBeforeNormalClose(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'ok']);
        mkdir($this->root . '/.agent-loop/edit/ABC-123', 0o775, true);

        self::assertSame(1, $this->runClose()['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());

        file_put_contents(
            $this->root . '/.agent-loop/edit/ABC-123/verification-result.json',
            json_encode(['status' => 'passed'], JSON_THROW_ON_ERROR),
        );
        self::assertSame(0, $this->runClose()['exit']);
        self::assertSame(SessionStatus::DONE, $this->sessionStatus());
    }

    public function testNonDoneWorkflowCloseIsRejected(): void
    {
        $result = $this->runClose(['ABC-123', '--status', 'dropped']);

        self::assertSame(1, $result['exit']);
        self::assertSame(SessionStatus::ACTIVE, $this->sessionStatus());
        self::assertStringContainsString('Use agent-loop session close directly', $result['output']);
    }

    /**

     * @param list<string> $args

     * @return array{exit: int, output: string}

     */
    private function runClose(array $args = [
        'ABC-123', '--status', 'done', '--learning-root', '__DEFAULT__',
    ]): array {
        if (($key = array_search('__DEFAULT__', $args, true)) !== false) {
            $args[$key] = $this->root . '/learning-root';
        }

        ob_start();
        $exit = (new WorkflowCloseCommand($this->root))->run($args);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function prepareGovernedRun(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Keep the task scope reviewable.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'lars',
        );
        $contract = $contracts->approve('ABC-123', 'lars');

        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $this->sessionId = $session->id;
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/learning-root');
        $this->runId = $run->runId;

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            10,
            'lars',
        );
        (new RunLearningDecisionStore($this->root . '/learning-root'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable learning from this close fixture.',
        );
    }

    private function breakVerifierForTask(): void
    {
        mkdir($this->root . '/.agent-loop/tasks', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tasks/ABC-123.md', '');
    }

    private function sessionStatus(): SessionStatus
    {
        return (new SessionStore())->load($this->root . '/.agent-loop/sessions', $this->sessionId)->status;
    }

    private function verificationReceipt(): string
    {
        return $this->root . '/.agent-loop/runs/ABC-123/verification.json';
    }

    /** @param array<string, mixed> $meta */
    private function writeRecallMeta(array $meta = []): void
    {
        $meta += [
            'task_id' => 'ABC-123',
            'compilation_id' => 'compilation.abc.default',
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ];
        mkdir($this->root . '/.agent-loop/recall/ABC-123', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/meta.json', json_encode($meta, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $data */
    private function writeReviewReport(array $data): void
    {
        if (!is_dir($this->root . '/.agent-loop/recall/ABC-123/reviews')) {
            mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        }
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode($data, JSON_THROW_ON_ERROR),
        );
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
