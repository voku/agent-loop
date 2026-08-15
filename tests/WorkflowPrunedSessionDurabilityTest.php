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
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\PostExecutionEvidenceBoundary;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentLoop\Workflow\WorkflowReportCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowPrunedSessionDurabilityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-pruned-run-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCompletedRunRemainsTheSameAuditableRunAfterSessionPrune(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Prove governed close survives Session pruning.',
            ['src/Foo.php'],
            ['Do not make Session durable.'],
            ['vendor/bin/phpunit'],
            'lars',
        );

        $approve = new WorkflowApproveCommand(
            $this->root,
            function (array $argv): int {
                $this->writeRecallMeta();

                return 0;
            },
        );
        ob_start();
        self::assertSame(0, $approve->run(['ABC-123', '--by', 'lars']));
        ob_end_clean();

        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");

        $contract = $contracts->load('ABC-123');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $sessions = new SessionStore();
        $sessionList = $sessions->all($this->root . '/.agent-loop/sessions');
        self::assertCount(1, $sessionList);
        $session = $sessionList[0];
        $run = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($run);

        (new ValidationEvidenceStore())->record(
            $session,
            1,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            12,
            'lars',
            implementationSnapshot: $snapshot->digest,
        );
        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode([
                'status' => 'ok',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
            ], JSON_THROW_ON_ERROR),
        );
        $boundary = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationSha256 = $boundary->validationEvidenceSha256();
        $reviewSha256 = $boundary->reviewEvidenceSha256();
        self::assertNotNull($validationSha256);
        self::assertNotNull($reviewSha256);
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable learning emerged from this regression proof.',
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
            validationEvidenceSha256: $validationSha256,
            reviewEvidenceSha256: $reviewSha256,
        );

        $cli = new WorkflowCli($this->root, static fn (array $argv): int => 0);
        ob_start();
        self::assertSame(0, $cli->run(['close', 'ABC-123', '--status', 'done']));
        ob_end_clean();

        $storedBeforePrune = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($storedBeforePrune);
        self::assertSame('complete', $storedBeforePrune['state'] ?? null);
        self::assertSame($run->runId, $storedBeforePrune['run_id'] ?? null);
        self::assertFileExists($this->root . '/.agent-loop/runs/ABC-123/verification.json');

        self::assertSame(
            [$session->id],
            $sessions->prune($this->root . '/.agent-loop/sessions', 0, [SessionStatus::DONE]),
        );
        self::assertDirectoryDoesNotExist($session->path);

        $contract = $contracts->load('ABC-123');
        self::assertSame('approved', $contract->status);
        self::assertSame('lars', $contract->approvedBy);

        $report = (new WorkflowReportCommand($this->root))->buildReport('ABC-123');
        self::assertSame('missing', $report['session']['status'] ?? null);
        self::assertSame('approved', $report['contract']['status'] ?? null);
        self::assertSame('Prove governed close survives Session pruning.', $report['contract']['goal'] ?? null);
        self::assertSame('passed', $report['validation'][0]['status'] ?? null);
        self::assertSame('verification_receipt', $report['validation'][0]['source'] ?? null);
        self::assertSame('no_durable_learning', $report['learning']['decision'] ?? null);

        $projected = (new RunManifestProjector($this->root))->project('ABC-123');
        self::assertSame($run->runId, $projected->runId, 'Pruning working memory must not change Run identity.');
        self::assertSame('complete', $projected->state, 'A completed Run must remain explainably complete after Session pruning.');
        self::assertSame('none', $projected->nextAction);
        self::assertSame('current', $projected->references['approval']['state'] ?? null);
        self::assertSame('passed', $projected->references['verification']['state'] ?? null);
        self::assertSame('decided', $projected->references['learning']['state'] ?? null);
        self::assertSame('missing', $projected->references['session']['state'] ?? null);
    }

    private function writeRecallMeta(): void
    {
        mkdir($this->root . '/.agent-loop/recall/ABC-123', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-prune-proof',
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
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
