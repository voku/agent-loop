<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentRecallCompiler\OutcomeLoggingConfig;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class WorkflowFinishJudgmentBoundaryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-finish-judgment-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', json_encode([
            'interaction' => ['human_explanations' => 'never'],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFinishGeneratesCurrentReviewThenRequiresExactAcknowledgementBeforeLearningAndClose(): void
    {
        [$runId, $sessionId] = $this->prepareRun('FINISH-3');
        $session = (new SessionStore())->load($this->root . '/.agent-loop/sessions', $sessionId);
        file_put_contents($session->path . '/review-marker.md', "FINISH-3 review blindspots\n");

        $first = $this->finish('FINISH-3', ['--format=json']);

        self::assertSame(1, $first['exit']);
        self::assertFalse($first['payload']['complete'] ?? true);
        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-3');
        self::assertTrue($review['exists']);
        self::assertFalse($review['invalid']);
        self::assertNotNull($review['sha256']);
        self::assertStringContainsString((string) $review['sha256'], (string) ($first['payload']['next_action'] ?? ''));
        self::assertStringContainsString('--reviewed-report-sha256', (string) ($first['payload']['next_action'] ?? ''));
        self::assertNull((new ReviewAcknowledgementStore($this->root))->find('FINISH-3'));

        $second = $this->finish('FINISH-3', [
            '--format=json',
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
            '--learning', 'no_durable_learning',
            '--learning-reason', 'No durable workflow learning from this bounded fixture.',
        ]);

        self::assertSame(0, $second['exit'], json_encode($second['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($second['payload']['complete'] ?? false);
        self::assertSame('none', $second['payload']['next_action'] ?? null);
        self::assertSame(
            SessionStatus::DONE,
            (new SessionStore())->load($this->root . '/.agent-loop/sessions', $sessionId)->status,
        );
        self::assertNotNull((new RunVerificationReceiptStore($this->root))->find('FINISH-3'));

        $acknowledgement = (new ReviewAcknowledgementStore($this->root))->find('FINISH-3');
        self::assertNotNull($acknowledgement);
        self::assertSame($runId, $acknowledgement->runId);
        self::assertSame($review['sha256'], $acknowledgement->reportSha256);
        self::assertSame('fixture-reviewer', $acknowledgement->acknowledgedBy);

        $run = (new GovernedRunStore($this->root))->find('FINISH-3');
        self::assertNotNull($run);
        $decision = (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($runId);
        self::assertNotNull($decision);
        self::assertSame(RunLearningDecisionStatus::NO_DURABLE_LEARNING, $decision->decision);
        self::assertSame($review['sha256'], $decision->reviewEvidenceSha256);

        $third = $this->finish('FINISH-3', ['--format=json']);
        self::assertSame(0, $third['exit']);
        self::assertTrue($third['payload']['complete'] ?? false);
    }

    public function testFollowUpLearningRefusalIsVisibleAndReferenceCompletesDecision(): void
    {
        [$runId] = $this->prepareRun('FINISH-FOLLOW-UP');
        $first = $this->finish('FINISH-FOLLOW-UP', ['--format=json']);

        self::assertSame(1, $first['exit']);
        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-FOLLOW-UP');
        self::assertTrue($review['exists']);
        self::assertFalse($review['invalid']);
        self::assertNotNull($review['sha256']);

        $missingReference = $this->finish('FINISH-FOLLOW-UP', [
            '--format=json',
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
            '--learning', 'follow_up_required',
            '--learning-reason', 'A bounded follow-up remains after this run.',
        ]);

        self::assertSame(1, $missingReference['exit']);
        self::assertFalse($missingReference['payload']['complete'] ?? true);
        self::assertSame('finish.closeout_failed', $missingReference['payload']['blockers'][0]['code'] ?? null);
        self::assertStringContainsString(
            'follow_up_required requires a follow-up reference.',
            (string) ($missingReference['payload']['blockers'][0]['message'] ?? ''),
        );

        $run = (new GovernedRunStore($this->root))->find('FINISH-FOLLOW-UP');
        self::assertNotNull($run);
        $learningStore = new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run));
        self::assertNull($learningStore->find($runId));

        $withReference = $this->finish('FINISH-FOLLOW-UP', [
            '--format=json',
            '--by', 'fixture-reviewer',
            '--learning', 'follow_up_required',
            '--learning-reason', 'A bounded follow-up remains after this run.',
            '--follow-up-ref', 'issue://voku/agent-loop/334',
        ]);

        self::assertSame(0, $withReference['exit'], json_encode($withReference['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($withReference['payload']['complete'] ?? false);
        self::assertSame('none', $withReference['payload']['next_action'] ?? null);

        $decision = (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($runId);
        self::assertNotNull($decision);
        self::assertSame(RunLearningDecisionStatus::FOLLOW_UP_REQUIRED, $decision->decision);
        self::assertSame('issue://voku/agent-loop/334', $decision->followUpRef);
    }

    public function testNoDurableLearningIsAnExplicitDecisionWithoutProse(): void
    {
        [$runId] = $this->prepareRun('FINISH-NO-PROSE');
        $this->finish('FINISH-NO-PROSE', ['--format=json']);
        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-NO-PROSE');
        self::assertNotNull($review['sha256']);

        $closed = $this->finish('FINISH-NO-PROSE', [
            '--format=json',
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
            '--learning', 'no_durable_learning',
        ]);

        self::assertSame(0, $closed['exit'], json_encode($closed['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($closed['payload']['complete'] ?? false);
        $run = (new GovernedRunStore($this->root))->find('FINISH-NO-PROSE');
        self::assertNotNull($run);
        $decision = (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($runId);
        self::assertNotNull($decision);
        self::assertSame(RunLearningDecisionStatus::NO_DURABLE_LEARNING, $decision->decision);
        self::assertNull($decision->reason);
    }

    public function testFollowUpReferenceAloneRecordsTheDecision(): void
    {
        [$runId] = $this->prepareRun('FINISH-IMPLIED-FOLLOW-UP');
        $this->finish('FINISH-IMPLIED-FOLLOW-UP', ['--format=json']);
        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-IMPLIED-FOLLOW-UP');

        $closed = $this->finish('FINISH-IMPLIED-FOLLOW-UP', [
            '--format=json',
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
            '--follow-up-ref', 'issue://voku/agent-loop/334',
        ]);

        self::assertSame(0, $closed['exit'], json_encode($closed['payload'], JSON_THROW_ON_ERROR));
        $run = (new GovernedRunStore($this->root))->find('FINISH-IMPLIED-FOLLOW-UP');
        self::assertNotNull($run);
        $decision = (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($runId);
        self::assertNotNull($decision);
        self::assertSame(RunLearningDecisionStatus::FOLLOW_UP_REQUIRED, $decision->decision);
        self::assertSame('issue://voku/agent-loop/334', $decision->followUpRef);
    }

    public function testExplicitStatusContradictingItsEvidenceIsRefused(): void
    {
        [$runId] = $this->prepareRun('FINISH-CONTRADICTION');
        $this->finish('FINISH-CONTRADICTION', ['--format=json']);
        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-CONTRADICTION');

        $refused = $this->finish('FINISH-CONTRADICTION', [
            '--format=json',
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
            '--learning', 'no_durable_learning',
            '--follow-up-ref', 'issue://voku/agent-loop/334',
        ]);

        self::assertNotSame(0, $refused['exit']);
        self::assertSame('error', $refused['payload']['status'] ?? null);
        self::assertStringContainsString(
            '--learning no_durable_learning contradicts the supplied follow-up reference',
            json_encode($refused['payload'], JSON_THROW_ON_ERROR),
        );
        $run = (new GovernedRunStore($this->root))->find('FINISH-CONTRADICTION');
        self::assertNotNull($run);
        self::assertNull((new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($runId));
    }

    public function testFindingAndFollowUpEvidenceCannotBeCombined(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Finding evidence cannot be combined with a follow-up reference.');

        \voku\AgentLoop\Workflow\WorkflowLearningRecorder::resolveDecision(
            null,
            true,
            'issue://voku/agent-loop/334',
        );
    }

    public function testWrongReviewDigestCannotCreateAcknowledgement(): void
    {
        $this->prepareRun('FINISH-4');
        self::assertSame(1, $this->finish('FINISH-4', ['--format=json'])['exit']);

        $result = $this->finish('FINISH-4', [
            '--format=json',
            '--reviewed-report-sha256', 'sha256:' . str_repeat('f', 64),
            '--by', 'fixture-reviewer',
        ]);

        self::assertSame(1, $result['exit']);
        self::assertNull((new ReviewAcknowledgementStore($this->root))->find('FINISH-4'));
    }

    public function testFinishFailsClosedWithoutReplacingInvalidPersistedReviewReport(): void
    {
        [$runId, $sessionId] = $this->prepareRun('FINISH-5');
        $reviewDirectory = $this->root . '/.agent-loop/recall/FINISH-5/reviews';
        mkdir($reviewDirectory, 0o775, true);
        $reviewPath = $reviewDirectory . '/FINISH-5.blindspots.json';
        $malformedReport = '{"version":2,"task_id":"FINISH-5","status":"ok","findings":';
        file_put_contents($reviewPath, $malformedReport);

        $result = $this->finish('FINISH-5', ['--format=json']);

        self::assertSame(1, $result['exit']);
        self::assertFalse($result['payload']['complete'] ?? true);
        self::assertSame('finish.closeout_failed', $result['payload']['blockers'][0]['code'] ?? null);
        self::assertStringContainsString(
            'Refusing to replace invalid persisted blind-spot report during finish reconciliation',
            (string) ($result['payload']['blockers'][0]['message'] ?? ''),
        );
        self::assertSame($malformedReport, file_get_contents($reviewPath));
        self::assertNull((new ReviewAcknowledgementStore($this->root))->find('FINISH-5'));
        self::assertSame(
            SessionStatus::ACTIVE,
            (new SessionStore())->load($this->root . '/.agent-loop/sessions', $sessionId)->status,
        );

        $run = (new GovernedRunStore($this->root))->find('FINISH-5');
        self::assertNotNull($run);
        self::assertNull(
            (new RunLearningDecisionStore(WorkflowLearningRoot::forRun($this->root, $run)))->find($runId),
        );
    }

    public function testSuppliedRecallOutcomeDraftClosesTheRunInTheSameFinishCall(): void
    {
        [, $sessionId] = $this->prepareRun('FINISH-RECALL-CLOSE', ['guidance.finish-recall-close']);
        $draft = $this->root . '/.agent-loop/recall/FINISH-RECALL-CLOSE/recall-log.draft.json';
        file_put_contents($draft, json_encode(['guidance_outcomes' => []], JSON_THROW_ON_ERROR));

        $first = $this->finish('FINISH-RECALL-CLOSE', ['--format=json']);
        self::assertSame(1, $first['exit']);

        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-RECALL-CLOSE');
        self::assertNotNull($review['sha256']);

        /** @var list<OutcomeLoggingConfig> $calls */
        $calls = [];
        $second = $this->finish(
            'FINISH-RECALL-CLOSE',
            [
                '--format=json',
                '--reviewed-report-sha256', (string) $review['sha256'],
                '--by', 'fixture-reviewer',
                '--learning', 'no_durable_learning',
                '--learning-reason', 'No durable workflow learning from this bounded fixture.',
                '--recall-outcome-draft', $draft,
                '--commit', 'working-tree',
            ],
            function (OutcomeLoggingConfig $config) use (&$calls): string {
                $calls[] = $config;
                $this->recordRecallOutcome('FINISH-RECALL-CLOSE', 'guidance.finish-recall-close');

                return 'finish-recall-close-fixture';
            },
        );

        self::assertSame(0, $second['exit'], json_encode($second['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($second['payload']['complete'] ?? false);
        self::assertSame('none', $second['payload']['next_action'] ?? null);
        self::assertSame(
            SessionStatus::DONE,
            (new SessionStore())->load($this->root . '/.agent-loop/sessions', $sessionId)->status,
        );

        // The draft is handed to Recall unchanged through its typed owner API:
        // agent-loop delegates, it does not decide or rewrite the judgment.
        self::assertCount(1, $calls);
        self::assertSame($this->root . '/.agent-loop/learning', $calls[0]->rootConfig->root);
        self::assertSame($draft, $calls[0]->draftPath);
        self::assertSame('fixture-reviewer', $calls[0]->actor);
        self::assertSame('working-tree', $calls[0]->commit);
    }

    public function testAcknowledgedReviewWithPendingRecallOutcomeEmitsOutcomeTemplateAndAdvancesGate(): void
    {
        [, $sessionId] = $this->prepareRun('FINISH-RECALL-GATE', ['guidance.finish-recall-gate']);
        $draft = $this->root . '/.agent-loop/recall/FINISH-RECALL-GATE/recall-log.draft.json';
        file_put_contents($draft, json_encode(['guidance_outcomes' => []], JSON_THROW_ON_ERROR));

        $first = $this->finish('FINISH-RECALL-GATE', ['--format=json']);
        self::assertSame(1, $first['exit']);

        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-RECALL-GATE');
        self::assertNotNull($review['sha256']);

        $ack = $this->finish('FINISH-RECALL-GATE', [
            '--format=json',
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
        ]);

        self::assertSame(1, $ack['exit']);
        self::assertFalse($ack['payload']['complete'] ?? true);
        self::assertSame('recall_outcomes', $ack['payload']['manifest']['references']['verification']['gate'] ?? null);
        self::assertSame('command_template', $ack['payload']['next_action_kind'] ?? null);
        $nextAction = (string) ($ack['payload']['next_action'] ?? '');
        self::assertStringContainsString('agent-loop finish FINISH-RECALL-GATE --recall-outcome-draft', $nextAction);
        self::assertStringContainsString('.agent-loop/recall/FINISH-RECALL-GATE/recall-log.draft.json', $nextAction);
        self::assertStringContainsString('--by <actor> --commit <commit>', $nextAction);
        self::assertStringNotContainsString('--learning', $nextAction);

        /** @var list<OutcomeLoggingConfig> $calls */
        $calls = [];
        $recorded = $this->finish(
            'FINISH-RECALL-GATE',
            [
                '--format=json',
                '--recall-outcome-draft', $draft,
                '--by', 'fixture-reviewer',
                '--commit', 'working-tree',
            ],
            function (OutcomeLoggingConfig $config) use (&$calls): string {
                $calls[] = $config;
                $this->recordRecallOutcome('FINISH-RECALL-GATE', 'guidance.finish-recall-gate');

                return 'finish-recall-gate-fixture';
            },
        );

        self::assertSame(1, $recorded['exit']);
        self::assertFalse($recorded['payload']['complete'] ?? true);
        self::assertSame('command_template', $recorded['payload']['next_action_kind'] ?? null);
        $advancedAction = (string) ($recorded['payload']['next_action'] ?? '');
        self::assertStringContainsString('agent-loop finish FINISH-RECALL-GATE --learning', $advancedAction);
        self::assertStringNotContainsString('--recall-outcome-draft', $advancedAction);
        self::assertCount(1, $calls);
        self::assertSame($this->root . '/.agent-loop/learning', $calls[0]->rootConfig->root);
        self::assertSame($draft, $calls[0]->draftPath);
        self::assertSame('fixture-reviewer', $calls[0]->actor);
        self::assertSame('working-tree', $calls[0]->commit);
    }

    public function testRefusedRecallOutcomeLogLeavesTheRunOpenWithTheOwnersReason(): void
    {
        [, $sessionId] = $this->prepareRun('FINISH-RECALL-REFUSED', ['guidance.finish-recall-refused']);
        $draft = $this->root . '/.agent-loop/recall/FINISH-RECALL-REFUSED/recall-log.draft.json';
        file_put_contents($draft, json_encode(['guidance_outcomes' => []], JSON_THROW_ON_ERROR));

        $first = $this->finish('FINISH-RECALL-REFUSED', ['--format=json']);
        self::assertSame(1, $first['exit']);

        $review = (new WorkflowReviewReportReader($this->root))->read('FINISH-RECALL-REFUSED');
        self::assertNotNull($review['sha256']);

        $second = $this->finish(
            'FINISH-RECALL-REFUSED',
            [
                '--format=json',
                '--reviewed-report-sha256', (string) $review['sha256'],
                '--by', 'fixture-reviewer',
                '--learning', 'no_durable_learning',
                '--learning-reason', 'No durable workflow learning from this bounded fixture.',
                '--recall-outcome-draft', $draft,
                '--commit', 'working-tree',
            ],
            static fn (OutcomeLoggingConfig $config): string => throw new RuntimeException(
                'placeholder outcome rows are not a judgment.',
            ),
        );

        self::assertSame(1, $second['exit']);
        self::assertFalse($second['payload']['complete'] ?? true);
        self::assertSame('finish.closeout_failed', $second['payload']['blockers'][0]['code'] ?? null);
        $message = (string) ($second['payload']['blockers'][0]['message'] ?? '');
        self::assertStringContainsString('placeholder outcome rows are not a judgment.', $message);
        self::assertSame(
            SessionStatus::ACTIVE,
            (new SessionStore())->load($this->root . '/.agent-loop/sessions', $sessionId)->status,
        );
    }

    public function testRecallOutcomeDraftRefusesWithoutTheRequiredActorAndCommit(): void
    {
        $this->prepareRun('FINISH-RECALL-INPUTS', ['guidance.finish-recall-inputs']);
        $draft = $this->root . '/.agent-loop/recall/FINISH-RECALL-INPUTS/recall-log.draft.json';
        file_put_contents($draft, "{}\n");

        $result = $this->finish(
            'FINISH-RECALL-INPUTS',
            ['--format=json', '--recall-outcome-draft', $draft, '--by', 'fixture-reviewer'],
            static fn (OutcomeLoggingConfig $config): string => throw new RuntimeException(
                'Recall must not be called without a commit.',
            ),
        );

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString(
            '--recall-outcome-draft requires --by <actor> and --commit <commit>.',
            (string) ($result['payload']['blockers'][0]['message'] ?? ''),
        );
    }

    private function recordRecallSelection(string $taskId, string $guidanceId, int $sequence): void
    {
        $this->appendLearningHistory('recall-selections.jsonl', [
            'schema_version' => '1.0',
            'id' => sprintf('recall-selection.%s.%03d', gmdate('Y-m-d'), $sequence),
            'task_id' => $taskId,
            'compilation_id' => strtolower($taskId) . '-fixture',
            'guidance_id' => $guidanceId,
            'guidance_type' => 'memory',
            'eligible' => true,
            'selected' => true,
            'selection_reason' => 'Fixture selection for the finish handover regression.',
            'task_files' => ['src/Foo.php'],
            'recorded_at' => gmdate('Y-m-d\\TH:i:sP'),
        ]);
    }

    private function recordRecallOutcome(string $taskId, string $guidanceId): void
    {
        $this->appendLearningHistory('outcomes.jsonl', [
            'schema_version' => '1.0',
            'id' => 'guidance-outcome.' . gmdate('Y-m-d') . '.001',
            'task_id' => $taskId,
            'compilation_id' => strtolower($taskId) . '-fixture',
            'guidance_id' => $guidanceId,
            'outcome' => 'helpful',
            'applied' => true,
            'comment' => 'Fixture outcome judged during the same finish invocation.',
            'commit' => 'working-tree',
            'recorded_by' => 'fixture-reviewer',
            'recorded_at' => gmdate('Y-m-d\\TH:i:sP'),
        ]);
    }

    /** @param array<string, mixed> $record */
    private function appendLearningHistory(string $file, array $record): void
    {
        $history = $this->root . '/.agent-loop/learning/history';
        if (!is_dir($history)) {
            mkdir($history, 0o775, true);
        }
        file_put_contents(
            $history . '/' . $file,
            json_encode($record, JSON_THROW_ON_ERROR) . "\n",
            FILE_APPEND,
        );
    }

    /**
     * @param list<string> $selectedGuidance
     * @return array{0: string, 1: string}
     */
    private function prepareRun(string $taskId, array $selectedGuidance = []): array
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Close through the finish lifecycle kernel.',
            ['src/Foo.php'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
        );
        $contract = $contracts->approve($taskId, 'fixture-approver');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', $taskId, by: 'fixture-agent');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        foreach ($selectedGuidance as $index => $guidanceId) {
            $this->recordRecallSelection($taskId, $guidanceId, $index + 1);
        }

        $recall = $this->root . '/.agent-loop/recall/' . $taskId;
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'compilation_id' => strtolower($taskId) . '-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => $selectedGuidance,
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved Contract commands.\n");

        return [$run->runId, $session->id];
    }

    /**
     * @param list<string> $options
     * @param null|callable(OutcomeLoggingConfig): string $recallOutcomeCloser
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function finish(string $taskId, array $options, ?callable $recallOutcomeCloser = null): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root, recallOutcomeCloser: $recallOutcomeCloser))
                ->run('finish', [$taskId, ...$options]);
            $stdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!is_string($stdout)) {
            throw new RuntimeException('Unable to capture finish JSON.');
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Finish JSON did not decode to an object.');
        }

        return ['exit' => $exit, 'payload' => $payload];
    }

    private function removeDirectory(string $path): void
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
