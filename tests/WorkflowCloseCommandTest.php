<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\PostExecutionEvidenceBoundary;
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
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
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

    public function testCloseAcceptsSelectedGuidanceWhoseAbsentOutcomeWasDeclared(): void
    {
        $this->writeRecallMeta([
            'task_id' => 'ABC-123',
            'compilation_id' => 'compilation.abc.002',
            'selected_guidance' => ['G-002'],
        ]);
        $this->writeReviewReport(['status' => 'ok']);
        $this->writeSelectionEvent('compilation.abc.002', 'G-002', 'This runner never reads the compiled briefing.');

        $result = $this->runClose();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('0 judged, 1 withheld with a stated reason', $result['output']);
    }

    public function testCloseStillRefusesAnEmptyWithheldReason(): void
    {
        $this->writeRecallMeta([
            'task_id' => 'ABC-123',
            'compilation_id' => 'compilation.abc.003',
            'selected_guidance' => ['G-003'],
        ]);
        $this->writeReviewReport(['status' => 'ok']);
        $this->writeSelectionEvent('compilation.abc.003', 'G-003', '   ');

        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('missing explicit recall outcome for: G-003', $result['output']);
    }

    public function testCloseRefusesWithholdingFromAnUnselectedEvent(): void
    {
        $this->writeRecallMeta([
            'task_id' => 'ABC-123',
            'compilation_id' => 'compilation.abc.004',
            'selected_guidance' => ['G-004'],
        ]);
        $this->writeReviewReport(['status' => 'ok']);
        $this->writeSelectionEvent(
            'compilation.abc.004',
            'G-004',
            'This row was evaluated but not selected.',
            false,
        );

        $result = $this->runClose();

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('missing explicit recall outcome for: G-004', $result['output']);
    }

    private function writeSelectionEvent(
        string $compilationId,
        string $guidanceId,
        string $withheldReason,
        bool $selected = true,
    ): void {
        $history = $this->root . '/.agent-loop/learning/history';
        if (!is_dir($history)) {
            mkdir($history, 0o775, true);
        }
        file_put_contents($history . '/recall-selections.jsonl', json_encode([
            'schema_version' => '1.0',
            'id' => 'recall-selection.2026-08-14.001',
            'compilation_id' => $compilationId,
            'task_id' => 'ABC-123',
            'guidance_id' => $guidanceId,
            'guidance_type' => 'memory',
            'eligible' => true,
            'selected' => $selected,
            'selection_reason' => 'scope_overlap',
            'exclusion_reason' => null,
            'task_files' => [],
            'recorded_at' => '2026-08-14T00:00:00+00:00',
            'outcome_withheld_reason' => $withheldReason,
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND);
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
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', (string) ($receipt['implementation_snapshot'] ?? ''));
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
    private function runClose(array $args = ['ABC-123', '--status', 'done']): array
    {
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
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $this->sessionId = $session->id;
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');
        $this->runId = $run->runId;

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            10,
            'lars',
            implementationSnapshot: $snapshot->digest,
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

    /** @param array<string, string|int> $data */
    private function writeReviewReport(array $data): void
    {
        if (!is_dir($this->root . '/.agent-loop/recall/ABC-123/reviews')) {
            mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        }
        $contract = (new TaskContractStore($this->root))->load('ABC-123');
        $session = (new SessionStore())->load($this->root . '/.agent-loop/sessions', $this->sessionId);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $data += [
            'contract_revision' => $contract->revision,
            'implementation_snapshot' => $snapshot->digest,
        ];
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode($data, JSON_THROW_ON_ERROR),
        );

        $boundary = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationSha256 = $boundary->validationEvidenceSha256();
        $reviewSha256 = $boundary->reviewEvidenceSha256();
        self::assertNotNull($validationSha256);
        self::assertNotNull($reviewSha256);
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $this->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable learning from this close fixture.',
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
            validationEvidenceSha256: $validationSha256,
            reviewEvidenceSha256: $reviewSha256,
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
