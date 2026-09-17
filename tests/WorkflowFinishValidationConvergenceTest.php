<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReviewPreparer;
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

    private function prepareRun(string $taskId, string $validation = 'php -r "exit(0);"'): Session
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Keep finish validation reconciliation independent of review ordering.',
            ['src/Foo.php'],
            [],
            [$validation],
            'fixture-planner',
        );
        $contract = $contracts->approve($taskId, 'fixture-approver');
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
