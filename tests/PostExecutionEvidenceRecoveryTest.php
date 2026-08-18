<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\PostExecutionEvidenceBoundary;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCloseCommand;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class PostExecutionEvidenceRecoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-post-execution-recovery-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'A';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testFreshBEvidenceRecoversWithoutRewritingAHistoryAndCloseProjectsComplete(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('RECOVER-1', 'Recover after stale implementation evidence.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('RECOVER-1', 'fixture');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'RECOVER-1', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');
        $this->writeRecallMeta('RECOVER-1');

        $a = ImplementationSnapshot::capture($this->root, $contract);
        $validationStore = new ValidationEvidenceStore();
        $validationStore->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::PASSED,
            0,
            recordedBy: 'fixture',
            implementationSnapshot: $a->digest,
        );
        $this->writeReview('RECOVER-1', $contract->revision, $a->digest);
        $boundaryA = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationA = $boundaryA->validationEvidenceSha256();
        $reviewA = $boundaryA->reviewEvidenceSha256();
        self::assertNotNull($validationA);
        self::assertNotNull($reviewA);
        $learning = new RunLearningDecisionStore($this->root . '/.agent-loop/learning');
        $learning->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'fixture',
            'Current for implementation A.',
            contractRevision: $contract->revision,
            implementationSnapshot: $a->digest,
            validationEvidenceSha256: $validationA,
            reviewEvidenceSha256: $reviewA,
        );

        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'B';\n");
        ob_start();
        $staleExit = (new WorkflowCloseCommand($this->root))->run(['RECOVER-1', '--status', 'done']);
        $staleOutput = (string) ob_get_clean();
        self::assertSame(1, $staleExit, $staleOutput);
        self::assertStringContainsString('stale validation evidence', $staleOutput);

        $b = ImplementationSnapshot::capture($this->root, $contract);
        self::assertNotSame($a->digest, $b->digest);
        $validationStore->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::PASSED,
            0,
            recordedBy: 'fixture',
            implementationSnapshot: $b->digest,
        );
        $this->writeReview('RECOVER-1', $contract->revision, $b->digest);
        $boundaryB = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationB = $boundaryB->validationEvidenceSha256();
        $reviewB = $boundaryB->reviewEvidenceSha256();
        self::assertNotNull($validationB);
        self::assertNotNull($reviewB);
        self::assertNotSame($validationA, $validationB);
        self::assertNotSame($reviewA, $reviewB);
        $learningB = $learning->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'fixture',
            'Current for implementation B.',
            contractRevision: $contract->revision,
            implementationSnapshot: $b->digest,
            validationEvidenceSha256: $validationB,
            reviewEvidenceSha256: $reviewB,
        );
        self::assertSame($b->digest, $learningB->implementationSnapshot);
        self::assertCount(2, $validationStore->all($session), 'A validation history must remain append-only after B is validated.');

        ob_start();
        $closeExit = (new WorkflowCloseCommand($this->root))->run(['RECOVER-1', '--status', 'done']);
        $closeOutput = (string) ob_get_clean();
        self::assertSame(0, $closeExit, $closeOutput);
        self::assertSame('complete', (new RunManifestProjector($this->root))->project('RECOVER-1')->state);
    }

    private function writeRecallMeta(string $taskId): void
    {
        $directory = $this->root . '/.agent-loop/recall/' . $taskId;
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($directory . '/meta.json', json_encode([
            'task_id' => $taskId,
            'compilation_id' => 'compilation.' . strtolower($taskId) . '.fixture',
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
    }

    private function writeReview(string $taskId, int $contractRevision, string $implementationSnapshot): void
    {
        $directory = $this->root . '/.agent-loop/recall/' . $taskId . '/reviews';
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($directory . '/' . $taskId . '.blindspots.json', json_encode([
            'version' => 2,
            'task_id' => $taskId,
            'status' => 'ok',
            'contract_revision' => $contractRevision,
            'implementation_snapshot' => $implementationSnapshot,
            'findings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        $contract = (new TaskContractStore($this->root))->load($taskId);
        $run = (new GovernedRunStore($this->root))->find($taskId);
        self::assertNotNull($run);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        self::assertSame($implementationSnapshot, $snapshot->digest);
        $review = (new WorkflowReviewReportReader($this->root))->read($taskId);
        self::assertSame('unacknowledged', $review['status']);
        self::assertNotNull($review['sha256']);
        (new ReviewAcknowledgementStore($this->root))->record(
            $run,
            $contract,
            $snapshot,
            $review['sha256'],
            'fixture',
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
