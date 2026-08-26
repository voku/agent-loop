<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\PostExecutionEvidenceBoundary;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCloseCommand;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowCloseReadinessStatusTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-close-readiness-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testFailedCurrentValidationBlocksStatusWithSameReasonAsClose(): void
    {
        $this->prepareGovernedRun(ValidationStatus::FAILED, 1);

        [$statusExit, $statusOutput] = $this->runStatus(['--format=json']);
        $status = json_decode($statusOutput, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $statusExit);
        self::assertSame('blocked', $status['manifest']['state'] ?? null);
        self::assertSame('blocked', $status['manifest']['references']['verification']['state'] ?? null);
        self::assertSame('validation', $status['manifest']['references']['verification']['gate'] ?? null);
        $reason = (string) ($status['manifest']['references']['verification']['reason'] ?? '');
        self::assertStringContainsString('vendor/bin/phpunit (failed)', $reason);
        // Naming finish here was the self-loop: finish re-runs the same failing
        // obligation. The canonical step is now the host work that can change it.
        self::assertSame('host_work', $status['manifest']['next_action_kind'] ?? null);
        self::assertStringContainsString(
            'change the implementation',
            (string) ($status['manifest']['next_action'] ?? ''),
        );

        [$closeExit, $closeOutput] = $this->runClose();
        self::assertSame(1, $closeExit);
        self::assertStringContainsString($reason, $closeOutput);
        self::assertStringContainsString('lifecycle state is blocked; session was not closed', $closeOutput);
    }

    public function testPassingCurrentValidationMakesStatusReadyThenCloseComplete(): void
    {
        $this->prepareGovernedRun(ValidationStatus::PASSED, 0);

        [$readyExit, $readyOutput] = $this->runStatus(['--format=json']);
        $ready = json_decode($readyOutput, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(0, $readyExit);
        self::assertSame('ready_to_close', $ready['manifest']['state'] ?? null);
        self::assertSame('ready', $ready['manifest']['references']['verification']['state'] ?? null);
        self::assertSame('agent-loop finish ABC-123', $ready['manifest']['next_action'] ?? null);

        [$closeExit, $closeOutput] = $this->runClose();
        self::assertSame(0, $closeExit, $closeOutput);

        [$completeExit, $completeOutput] = $this->runStatus(['--format=json']);
        $complete = json_decode($completeOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $completeExit);
        self::assertSame('complete', $complete['manifest']['state'] ?? null);
        self::assertSame('passed', $complete['manifest']['references']['verification']['state'] ?? null);
        self::assertSame('none', $complete['manifest']['next_action'] ?? null);
    }

    public function testWhitespaceCompilationIdIsNotAcceptedAsIdentifyingACompilation(): void
    {
        $this->prepareGovernedRun(ValidationStatus::PASSED, 0);

        // The owner reader reports the empty string as an absent compilation
        // id but returns a whitespace-only one unchanged. Selected guidance
        // may not be matched against outcome records under such an id.
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'task_id' => 'ABC-123',
                'compilation_id' => '   ',
                'selected_guidance' => ['guidance.readiness-fixture'],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );

        [$closeExit, $closeOutput] = $this->runClose();

        self::assertSame(1, $closeExit, $closeOutput);
        self::assertStringContainsString('selected guidance without a compilation id', $closeOutput);
        self::assertStringNotContainsString('missing explicit recall outcome for', $closeOutput);
    }

    public function testMissingRecallOutcomeRoutesToTheJudgmentThatCanAdvanceIt(): void
    {
        $this->prepareGovernedRun(ValidationStatus::PASSED, 0);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'task_id' => 'ABC-123',
                'compilation_id' => 'readiness-fixture',
                'selected_guidance' => ['guidance.readiness-fixture'],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/recall-log.draft.json',
            "{}\n",
        );

        [$statusExit, $statusOutput] = $this->runStatus(['--format=json']);
        $status = json_decode($statusOutput, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(2, $statusExit);
        self::assertSame('recall_outcomes', $status['manifest']['references']['verification']['gate'] ?? null);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $status['manifest']['next_action_kind'] ?? null);
        $action = (string) ($status['manifest']['next_action'] ?? '');
        self::assertStringContainsString('agent-loop recall log-outcome', $action);
        self::assertStringContainsString('.agent-loop/recall/ABC-123/recall-log.draft.json', $action);
        self::assertStringNotContainsString('workflow status', $action);
    }

    private function prepareGovernedRun(ValidationStatus $validationStatus, int $exitCode): Session
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Make status and close answer the same readiness question.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'lars',
        );
        $contract = $contracts->approve('ABC-123', 'lars');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'task_id' => 'ABC-123',
                'compilation_id' => 'readiness-fixture',
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode([
                'version' => 2,
                'task_id' => 'ABC-123',
                'status' => 'ok',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'vendor/bin/phpunit',
            $validationStatus,
            $exitCode,
            10,
            'lars',
            implementationSnapshot: $snapshot->digest,
        );

        $review = (new WorkflowReviewReportReader($this->root))->read('ABC-123');
        self::assertSame('unacknowledged', $review['status']);
        self::assertNotNull($review['sha256']);
        (new ReviewAcknowledgementStore($this->root))->record(
            $run,
            $contract,
            $snapshot,
            $review['sha256'],
            'lars',
        );

        $boundary = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationSha = $boundary->validationEvidenceSha256();
        $reviewSha = $boundary->reviewEvidenceSha256();
        self::assertNotNull($validationSha);
        self::assertNotNull($reviewSha);

        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'Readiness regression fixture has no durable learning.',
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
            validationEvidenceSha256: $validationSha,
            reviewEvidenceSha256: $reviewSha,
        );

        return $session;
    }

    /**
     * @param list<string> $options
     * @return array{0: int, 1: string}
     */
    private function runStatus(array $options): array
    {
        ob_start();
        $exit = (new WorkflowStatusCommand($this->root))->run(['ABC-123', ...$options]);
        $output = (string) ob_get_clean();

        return [$exit, $output];
    }

    /** @return array{0: int, 1: string} */
    private function runClose(): array
    {
        ob_start();
        $exit = (new WorkflowCloseCommand($this->root))->run(['ABC-123', '--status', 'done']);
        $output = (string) ob_get_clean();

        return [$exit, $output];
    }

    private function rm(string $dir): void
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
