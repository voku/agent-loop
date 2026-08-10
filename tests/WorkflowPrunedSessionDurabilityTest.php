<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentLoop\Workflow\WorkflowReportCommand;
use voku\AgentSession\LearningDecision;
use voku\AgentSession\LearningDecisionStore;
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
        mkdir($this->root . '/learning-root', 0o775, true);
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

        $sessions = new SessionStore();
        $sessionList = $sessions->all($this->root . '/session_plan');
        self::assertCount(1, $sessionList);
        $session = $sessionList[0];

        (new ValidationEvidenceStore())->record(
            $session,
            1,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            12,
            'lars',
        );
        (new LearningDecisionStore())->decide($session, LearningDecision::NO_DURABLE_LEARNING, 'lars');
        mkdir($this->root . '/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
        );

        $cli = new WorkflowCli($this->root, static fn (array $argv): int => 0);
        ob_start();
        self::assertSame(0, $cli->run(['close', 'ABC-123', '--status', 'done']));
        ob_end_clean();

        $storedBeforePrune = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($storedBeforePrune);
        self::assertSame('complete', $storedBeforePrune['state'] ?? null);
        $runId = $storedBeforePrune['run_id'] ?? null;
        self::assertIsString($runId);

        self::assertSame(
            [$session->id],
            $sessions->prune($this->root . '/session_plan', 0, [SessionStatus::DONE]),
        );
        self::assertDirectoryDoesNotExist($session->path);

        $contract = $contracts->load('ABC-123');
        self::assertSame('approved', $contract->status);
        self::assertSame('lars', $contract->approvedBy);

        $report = (new WorkflowReportCommand($this->root))->buildReport('ABC-123');
        self::assertSame('missing', $report['session']['status'] ?? null);
        self::assertSame('approved', $report['contract']['status'] ?? null);
        self::assertSame('Prove governed close survives Session pruning.', $report['contract']['goal'] ?? null);

        $projected = (new RunManifestProjector($this->root))->project('ABC-123');
        self::assertSame($runId, $projected->runId, 'Pruning working memory must not change Run identity.');
        self::assertSame('complete', $projected->state, 'A completed Run must remain explainably complete after Session pruning.');
        self::assertSame('none', $projected->nextAction);
        self::assertSame('current', $projected->references['approval']['state'] ?? null);
        self::assertSame('passed', $projected->references['verification']['state'] ?? null);
        self::assertSame('decided', $projected->references['learning']['state'] ?? null);
    }

    private function writeRecallMeta(): void
    {
        mkdir($this->root . '/recall/ABC-123', 0o775, true);
        file_put_contents(
            $this->root . '/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-prune-proof',
                'selected_guidance' => [],
                'selected_constraints' => [],
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
