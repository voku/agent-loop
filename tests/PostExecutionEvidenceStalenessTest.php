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
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class PostExecutionEvidenceStalenessTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-post-execution-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'A';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testValidationPassForImplementationACannotCloseImplementationB(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('STALE-1', 'Freeze post-execution evidence.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('STALE-1', 'fixture');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'STALE-1', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $this->writeRecallMeta('STALE-1');
        $this->writeReview('STALE-1', ['status' => 'ok']);
        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::PASSED,
            0,
            recordedBy: 'fixture',
        );
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'fixture',
            'Decision observed implementation A.',
        );

        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'B';\n");

        ob_start();
        $exit = (new WorkflowCloseCommand($this->root))->run(['STALE-1', '--status', 'done']);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('stale validation evidence', $output);
    }

    public function testReviewForImplementationACannotCloseImplementationBAfterRevalidation(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('STALE-2', 'Freeze review identity.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('STALE-2', 'fixture');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'STALE-2', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');
        $a = ImplementationSnapshot::capture($this->root, $contract);

        $this->writeRecallMeta('STALE-2');
        $this->writeReview('STALE-2', [
            'status' => 'ok',
            'contract_revision' => $contract->revision,
            'implementation_snapshot' => $a->digest,
        ]);
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'fixture',
            'Decision observed implementation A.',
        );

        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'B';\n");
        $b = ImplementationSnapshot::capture($this->root, $contract);
        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::PASSED,
            0,
            recordedBy: 'fixture',
            implementationSnapshot: $b->digest,
        );

        ob_start();
        $exit = (new WorkflowCloseCommand($this->root))->run(['STALE-2', '--status', 'done']);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('stale review evidence', $output);
    }

    public function testLearningForImplementationACannotCloseImplementationBAfterFreshValidationAndReview(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('STALE-3', 'Freeze Learning identity.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('STALE-3', 'fixture');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'STALE-3', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');
        $a = ImplementationSnapshot::capture($this->root, $contract);

        $this->writeRecallMeta('STALE-3');
        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::PASSED,
            0,
            recordedBy: 'fixture',
            implementationSnapshot: $a->digest,
        );
        $this->writeReview('STALE-3', [
            'status' => 'ok',
            'contract_revision' => $contract->revision,
            'implementation_snapshot' => $a->digest,
        ]);
        $boundaryA = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationA = $boundaryA->validationEvidenceSha256();
        $reviewA = $boundaryA->reviewEvidenceSha256();
        self::assertNotNull($validationA);
        self::assertNotNull($reviewA);
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'fixture',
            'Decision observed implementation A.',
            contractRevision: $contract->revision,
            implementationSnapshot: $a->digest,
            validationEvidenceSha256: $validationA,
            reviewEvidenceSha256: $reviewA,
        );

        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'B';\n");
        $b = ImplementationSnapshot::capture($this->root, $contract);
        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::PASSED,
            0,
            recordedBy: 'fixture',
            implementationSnapshot: $b->digest,
        );
        $this->writeReview('STALE-3', [
            'status' => 'ok',
            'contract_revision' => $contract->revision,
            'implementation_snapshot' => $b->digest,
        ]);

        ob_start();
        $exit = (new WorkflowCloseCommand($this->root))->run(['STALE-3', '--status', 'done']);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit, $output);
        self::assertStringContainsString('stale Learning decision', $output);
    }

    private function writeRecallMeta(string $taskId): void
    {
        $directory = $this->root . '/.agent-loop/recall/' . $taskId;
        mkdir($directory, 0o775, true);
        file_put_contents($directory . '/meta.json', json_encode([
            'task_id' => $taskId,
            'compilation_id' => 'compilation.' . strtolower($taskId) . '.fixture',
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $report */
    private function writeReview(string $taskId, array $report): void
    {
        $directory = $this->root . '/.agent-loop/recall/' . $taskId . '/reviews';
        mkdir($directory, 0o775, true);
        $report += [
            'version' => 2,
            'task_id' => $taskId,
            'findings' => [],
        ];
        file_put_contents(
            $directory . '/' . $taskId . '.blindspots.json',
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
