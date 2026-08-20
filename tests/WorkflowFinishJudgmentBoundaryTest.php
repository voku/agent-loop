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

    /** @return array{0: string, 1: string} */
    private function prepareRun(string $taskId): array
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

        $recall = $this->root . '/.agent-loop/recall/' . $taskId;
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => $taskId,
            'compilation_id' => strtolower($taskId) . '-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved Contract commands.\n");

        return [$run->runId, $session->id];
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function finish(string $taskId, array $options): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('finish', [$taskId, ...$options]);
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
