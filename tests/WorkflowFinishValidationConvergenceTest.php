<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReviewPreparer;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowFinishValidationConvergenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-finish-validation-convergence-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root . '/src', 0o775, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Unable to create source fixture directory.');
        }
        if (!mkdir($this->root . '/.agent-loop/learning', 0o775, true) && !is_dir($this->root . '/.agent-loop/learning')) {
            throw new RuntimeException('Unable to create learning fixture directory.');
        }
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testReviewPreparedBeforeFirstFinishDoesNotSkipValidationEvidence(): void
    {
        $session = $this->prepareRun('FINISH-REVIEW-FIRST');
        $contract = (new TaskContractStore($this->root))->load('FINISH-REVIEW-FIRST');
        $review = (new WorkflowReviewPreparer($this->root))->prepare($contract);

        self::assertNotNull($review['sha256']);
        self::assertCount(0, (new ValidationEvidenceStore())->all($session));

        $acknowledged = $this->finish('FINISH-REVIEW-FIRST', [
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
        ]);

        self::assertSame(1, $acknowledged['exit']);
        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(1, $evidence, 'finish must reconcile validation even when review already exists.');
        self::assertSame(ValidationStatus::PASSED, $evidence[0]->status);
        self::assertSame('php -r "exit(0);"', $evidence[0]->command);
        self::assertStringContainsString('--learning', (string) ($acknowledged['payload']['next_action'] ?? ''));

        $learned = $this->finish('FINISH-REVIEW-FIRST', [
            '--learning', 'no_durable_learning',
            '--learning-reason', 'No durable learning from this bounded regression.',
            '--by', 'fixture-reviewer',
        ]);

        self::assertSame(0, $learned['exit'], json_encode($learned['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($learned['payload']['complete'] ?? false);
        self::assertSame('none', $learned['payload']['next_action'] ?? null);
    }

    public function testFinishPreparedReviewUsesConfiguredPromptPresentation(): void
    {
        file_put_contents($this->root . '/.agent-loop/init.json', json_encode([
            'prompting' => ['language' => 'de', 'blindspots' => ['tone' => 'unflinching']],
        ], JSON_THROW_ON_ERROR));
        $this->prepareRun('FINISH-LOCALIZED');
        $contract = (new TaskContractStore($this->root))->load('FINISH-LOCALIZED');

        (new WorkflowReviewPreparer($this->root))->prepare($contract);

        $prompt = (string) file_get_contents($this->root . '/.agent-loop/recall/FINISH-LOCALIZED/reviews/FINISH-LOCALIZED.blindspots.prompt.md');
        self::assertStringContainsString('Write the final review in language de.', $prompt);
        self::assertStringContainsString('Be unflinching about evidenced defects', $prompt);
    }

    public function testReviewDoesNotTurnFailedValidationIntoAnAutomaticRetry(): void
    {
        $session = $this->prepareRun('FINISH-REVIEW-FAILED', 'php -r "exit(7);"');

        $failed = $this->finish('FINISH-REVIEW-FAILED', []);

        self::assertSame(1, $failed['exit']);
        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(1, $evidence);
        self::assertSame(ValidationStatus::FAILED, $evidence[0]->status);
        self::assertSame(7, $evidence[0]->exitCode);

        $contract = (new TaskContractStore($this->root))->load('FINISH-REVIEW-FAILED');
        $review = (new WorkflowReviewPreparer($this->root))->prepare($contract);
        self::assertNotNull($review['sha256']);

        $acknowledged = $this->finish('FINISH-REVIEW-FAILED', [
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
        ]);

        self::assertSame(1, $acknowledged['exit']);
        self::assertSame('host_work', $acknowledged['payload']['next_action_kind'] ?? null);
        $after = (new ValidationEvidenceStore())->all($session);
        self::assertCount(1, $after, 'Current failed validation must remain host work, not be auto-retried by finish.');
        self::assertSame(ValidationStatus::FAILED, $after[0]->status);
        self::assertSame(7, $after[0]->exitCode);
    }

    public function testFinishReconcilesStaleValidationWithCurrentAcknowledgedReview(): void
    {
        $taskId = 'FINISH-STALE';
        $session = $this->prepareRun($taskId);
        $first = $this->finish($taskId, []);
        $oldReview = $first['payload']['manifest']['references']['review']['source']['sha256'] ?? null;
        self::assertIsString($oldReview);
        $this->finish($taskId, ['--reviewed-report-sha256', $oldReview, '--by', 'fixture-reviewer']);
        self::assertCount(1, (new ValidationEvidenceStore())->all($session));

        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'changed';\n");
        $contract = (new TaskContractStore($this->root))->load($taskId);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $currentReview = (new WorkflowReviewPreparer($this->root))->prepare($contract);
        self::assertIsString($currentReview['sha256']);
        $this->finish($taskId, ['--reviewed-report-sha256', $currentReview['sha256'], '--by', 'fixture-reviewer']);
        $result = $this->finish($taskId, []);

        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(2, $evidence, 'finish must rerun stale validation for the new implementation.');
        self::assertSame(ValidationStatus::PASSED, $evidence[1]->status);
        self::assertSame($snapshot->digest, $evidence[1]->implementationSnapshot);
        $review = (new WorkflowReviewReportReader($this->root))->read($taskId);
        self::assertSame($snapshot->digest, $review['implementation_snapshot']);
        self::assertNotSame($oldReview, $review['sha256']);
        self::assertSame($currentReview['sha256'], $review['sha256']);
        self::assertStringContainsString('--learning', (string) ($result['payload']['next_action'] ?? ''));
        self::assertFalse($result['payload']['complete'] ?? true);
    }

    public function testStaleRevalidationFailureDoesNotRecordLearningOrRetryCurrentFailure(): void
    {
        $taskId = 'FINISH-STALE-FAIL';
        $session = $this->prepareRun($taskId, 'php -l src/Foo.php');
        $first = $this->finish($taskId, []);
        $oldReview = $first['payload']['manifest']['references']['review']['source']['sha256'] ?? null;
        self::assertIsString($oldReview);
        $this->finish($taskId, ['--reviewed-report-sha256', $oldReview, '--by', 'fixture-reviewer']);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nthis is invalid php\n");
        $contract = (new TaskContractStore($this->root))->load($taskId);
        $review = (new WorkflowReviewPreparer($this->root))->prepare($contract);
        self::assertIsString($review['sha256']);
        $result = $this->finish($taskId, ['--reviewed-report-sha256', $review['sha256'], '--by', 'fixture-reviewer']);
        ob_start();
        $exit = (new HostFrontDoorApplication($this->root))->run('finish', [$taskId, '--format=json', '--learning', 'no_durable_learning', '--by', 'fixture-reviewer']);
        $payload = json_decode((string) ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $exit);
        self::assertSame('refused', $payload['mutation_status'] ?? null);
        $result = ['payload' => $payload];

        $evidence = (new ValidationEvidenceStore())->all($session);
        self::assertCount(2, $evidence);
        self::assertSame(ValidationStatus::FAILED, $evidence[1]->status);
        self::assertSame('host_work', $result['payload']['next_action_kind'] ?? null);
        self::assertNotSame('decided', $result['payload']['manifest']['references']['learning']['state'] ?? null);
        $this->finish($taskId, []);
        self::assertCount(2, (new ValidationEvidenceStore())->all($session), 'Current failed validation must not be retried automatically.');
    }

    public function testCurrentFailureIsNotRetriedWhenAnotherValidationIsStale(): void
    {
        $taskId = 'FINISH-MIXED';
        $commands = ['php -l src/Foo.php', 'php -r "exit(0);"'];
        $session = $this->prepareRun($taskId, $commands);
        $first = $this->finish($taskId, []);
        $oldReview = $first['payload']['manifest']['references']['review']['source']['sha256'] ?? null;
        self::assertIsString($oldReview);
        $this->finish($taskId, ['--reviewed-report-sha256', $oldReview, '--by', 'fixture-reviewer']);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'changed';\n");
        $contract = (new TaskContractStore($this->root))->load($taskId);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        (new ValidationEvidenceStore())->record($session, $contract->revision, $commands[0], ValidationStatus::FAILED, 1, recordedBy: 'fixture', implementationSnapshot: $snapshot->digest);
        $review = (new WorkflowReviewPreparer($this->root))->prepare($contract);
        self::assertIsString($review['sha256']);
        $this->finish($taskId, ['--reviewed-report-sha256', $review['sha256'], '--by', 'fixture-reviewer']);
        $result = $this->finish($taskId, ['--learning', 'no_durable_learning', '--by', 'fixture-reviewer']);

        self::assertCount(3, (new ValidationEvidenceStore())->all($session));
        self::assertSame('host_work', $result['payload']['next_action_kind'] ?? null);
        self::assertNotSame('decided', $result['payload']['manifest']['references']['learning']['state'] ?? null);
    }

    public function testCurrentValidationWithStaleReviewRequiresNewAcknowledgement(): void
    {
        $taskId = 'FINISH-STALE-REVIEW';
        $session = $this->prepareRun($taskId);
        $first = $this->finish($taskId, []);
        $oldReview = $first['payload']['manifest']['references']['review']['source']['sha256'] ?? null;
        self::assertIsString($oldReview);
        $this->finish($taskId, ['--reviewed-report-sha256', $oldReview, '--by', 'fixture-reviewer']);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'changed';\n");
        $contract = (new TaskContractStore($this->root))->load($taskId);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        (new ValidationEvidenceStore())->record($session, $contract->revision, 'php -r "exit(0);"', ValidationStatus::PASSED, 0, recordedBy: 'fixture', implementationSnapshot: $snapshot->digest);

        $result = $this->finish($taskId, []);
        $review = (new WorkflowReviewReportReader($this->root))->read($taskId);
        self::assertSame($snapshot->digest, $review['implementation_snapshot']);
        self::assertNotSame($oldReview, $review['sha256']);
        self::assertStringContainsString('--reviewed-report-sha256', (string) ($result['payload']['next_action'] ?? ''));
        self::assertCount(2, (new ValidationEvidenceStore())->all($session));
    }

    public function testCurrentFailureIsNotRetriedWhileReviewIsStale(): void
    {
        $taskId = 'FINISH-MIXED-STALE-REVIEW';
        $commands = ['php -l src/Foo.php', 'php -r "exit(0);"'];
        $session = $this->prepareRun($taskId, $commands);
        $first = $this->finish($taskId, []);
        $review = $first['payload']['manifest']['references']['review']['source']['sha256'] ?? null;
        self::assertIsString($review);
        $this->finish($taskId, ['--reviewed-report-sha256', $review, '--by', 'fixture-reviewer']);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'changed';\n");
        $contract = (new TaskContractStore($this->root))->load($taskId);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        (new ValidationEvidenceStore())->record($session, $contract->revision, $commands[0], ValidationStatus::FAILED, 1, recordedBy: 'fixture', implementationSnapshot: $snapshot->digest);

        $result = $this->finish($taskId, []);
        self::assertCount(3, (new ValidationEvidenceStore())->all($session));
        self::assertSame('host_work', $result['payload']['next_action_kind'] ?? null);
        self::assertFalse($result['payload']['complete'] ?? true);
    }

    public function testInvalidReviewIsNotReplacedWhenValidationIsStale(): void
    {
        $taskId = 'FINISH-INVALID-REVIEW';
        $this->prepareRun($taskId);
        $this->finish($taskId, []);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'changed';\n");
        $path = $this->root . '/.agent-loop/recall/' . $taskId . '/reviews/' . $taskId . '.blindspots.json';
        file_put_contents($path, '{invalid');

        $result = $this->finish($taskId, []);
        self::assertSame('{invalid', file_get_contents($path));
        self::assertFalse($result['payload']['complete'] ?? true);
        self::assertSame('invalid', $result['payload']['manifest']['references']['review']['state'] ?? null);
    }

    public function testFinishWithActiveCardLeavesRunCompleteWhileRequiringBoardReconciliation(): void
    {
        mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/todo/kanban.config.json',
            json_encode(['projectPrefix' => 'FINISH'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/FINISH-1.md',
            "# FINISH-1: Active work\n\n- **Ticket:** FINISH-1\n- **Lane:** DOING\n- **Status:** In Progress\n\n## Brief\nActive.\n",
        );

        $session = $this->prepareRun('FINISH-1');
        $contract = (new TaskContractStore($this->root))->load('FINISH-1');
        $review = (new WorkflowReviewPreparer($this->root))->prepare($contract);

        $this->finish('FINISH-1', [
            '--reviewed-report-sha256', (string) $review['sha256'],
            '--by', 'fixture-reviewer',
        ]);

        $learned = $this->finish('FINISH-1', [
            '--learning', 'no_durable_learning',
            '--learning-reason', 'No durable learning.',
            '--by', 'fixture-reviewer',
        ]);

        self::assertSame(0, $learned['exit'], json_encode($learned['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($learned['payload']['complete'] ?? false, 'Run must be complete.');
        self::assertSame('host_work', $learned['payload']['next_action_kind'] ?? null);
        self::assertStringContainsString('board.active_after_run_complete', (string) ($learned['payload']['next_action'] ?? ''));
        self::assertStringContainsString('agent-kanban', (string) ($learned['payload']['next_action'] ?? ''));
    }

    /** @param string|list<string> $validation */
    private function prepareRun(string $taskId, string|array $validation = 'php -r "exit(0);"'): Session
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Keep finish validation reconciliation independent of review ordering.',
            ['src/Foo.php'],
            [],
            is_array($validation) ? $validation : [$validation],
            'fixture-planner',
        );
        $contract = $contracts->approve($taskId, 'fixture-approver', $contracts->load($taskId)->revision);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', $taskId, by: 'fixture-agent');
        (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $recallDirectory = $this->root . '/.agent-loop/recall/' . $taskId;
        if (!mkdir($recallDirectory, 0o775, true) && !is_dir($recallDirectory)) {
            throw new RuntimeException('Unable to create Recall fixture directory.');
        }
        file_put_contents(
            $recallDirectory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => $taskId,
                'compilation_id' => strtolower($taskId) . '-fixture',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($recallDirectory . '/validation-plan.md', "# Validation\n\nRun the approved commands.\n");

        return $session;
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function finish(string $taskId, array $options): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run(
                'finish',
                [$taskId, '--format=json', ...$options],
            );
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
