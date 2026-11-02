<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowLearningEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-learning-boundary-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'A';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testLearningCannotConcludeBeforeCurrentValidationAndReviewEvidenceExists(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('LEARN-STALE-1', 'Bind Learning to post-execution evidence.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('LEARN-STALE-1', 'fixture', $contracts->load('LEARN-STALE-1')->revision);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'LEARN-STALE-1', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $exit = (new WorkflowLearningCommand($this->root))->run([
            'LEARN-STALE-1',
            '--status', 'no_durable_learning',
            '--by', 'fixture',
            '--reason', 'Premature conclusion before validation and review.',
        ]);

        self::assertSame(1, $exit, 'Learning must not become authoritative before the current post-execution evidence boundary exists.');
        self::assertNull(
            (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->find($run->runId),
            'Rejected Learning must not persist a durable Run decision.',
        );
    }

    public function testLearningCanConcludeFromCurrentFailedValidationAndReviewEvidence(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('LEARN-FAILED-1', 'Learn from current failed post-execution evidence.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('LEARN-FAILED-1', 'fixture', $contracts->load('LEARN-FAILED-1')->revision);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'LEARN-FAILED-1', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::FAILED,
            1,
            10,
            'fixture',
            implementationSnapshot: $snapshot->digest,
        );

        $reviewRoot = $this->root . '/.agent-loop/recall/LEARN-FAILED-1/reviews';
        mkdir($reviewRoot, 0o775, true);
        file_put_contents(
            $reviewRoot . '/LEARN-FAILED-1.blindspots.json',
            json_encode([
                'version' => 2,
                'task_id' => 'LEARN-FAILED-1',
                'status' => 'fail',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
                'findings' => [[
                    'id' => 'fixture_failure',
                    'severity' => 'FAIL',
                    'message' => 'Current failed evidence requires a learning decision.',
                    'evidence' => ['php -l src/Foo.php failed for the current implementation.'],
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $exit = (new WorkflowLearningCommand($this->root))->run([
            'LEARN-FAILED-1',
            '--status', 'no_durable_learning',
            '--by', 'fixture',
        ]);

        self::assertSame(0, $exit, 'Learning must remain available for current failed validation/review evidence.');
        $decision = (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->find($run->runId);
        self::assertNotNull($decision, 'Current failed evidence must still be bindable to a durable Run learning decision.');
        self::assertNull($decision->reason, 'A learning decision needs no prose.');
    }

    public function testNamedLearningDecisionCanReplaceBoundLegacyReservedDecision(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('LEARN-LEGACY-1', 'Recover a legacy decision at the same evidence boundary.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('LEARN-LEGACY-1', 'fixture', $contracts->load('LEARN-LEGACY-1')->revision);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'LEARN-LEGACY-1', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::FAILED,
            1,
            10,
            'fixture',
            implementationSnapshot: $snapshot->digest,
        );
        $reviewRoot = $this->root . '/.agent-loop/recall/LEARN-LEGACY-1/reviews';
        mkdir($reviewRoot, 0o775, true);
        file_put_contents(
            $reviewRoot . '/LEARN-LEGACY-1.blindspots.json',
            json_encode([
                'version' => 2,
                'task_id' => 'LEARN-LEGACY-1',
                'status' => 'fail',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
                'findings' => [[
                    'id' => 'fixture_failure',
                    'severity' => 'FAIL',
                    'message' => 'Current failed evidence requires a learning decision.',
                    'evidence' => ['The current implementation failed validation.'],
                ]],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $command = new WorkflowLearningCommand($this->root);
        self::assertSame(0, $command->run([
            'LEARN-LEGACY-1',
            '--status', 'no_durable_learning',
            '--by', 'fixture',
        ]));

        $store = new RunLearningDecisionStore($this->root . '/.agent-loop/learning');
        $path = $store->path($run->runId);
        $legacy = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($legacy);
        $legacy['decided_by'] = 'standing:legacy-reviewer';
        self::assertNotFalse(file_put_contents(
            $path,
            json_encode($legacy, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        ));

        self::assertSame(0, $command->run([
            'LEARN-LEGACY-1',
            '--status', 'no_durable_learning',
            '--by', 'named-reviewer',
        ]));

        $replacement = $store->find($run->runId);
        self::assertNotNull($replacement);
        self::assertSame('named-reviewer', $replacement->decidedBy);
        self::assertSame($contract->revision, $replacement->contractRevision);
        self::assertSame($snapshot->digest, $replacement->implementationSnapshot);
        $archive = glob(dirname($path) . '/archive/' . hash('sha256', $run->runId) . '/*.json') ?: [];
        self::assertCount(1, $archive);
        $archived = json_decode((string) file_get_contents($archive[0]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('standing:legacy-reviewer', $archived['decided_by'] ?? null);
    }

    public function testWorkflowLearnRejectsReservedActorBeforePersistingDecision(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('LEARN-STANDING-1', 'Reject reserved actors at the direct workflow learn boundary.', ['src/Foo.php'], [], ['php -l src/Foo.php'], 'fixture');
        $contract = $contracts->approve('LEARN-STANDING-1', 'fixture', $contracts->load('LEARN-STANDING-1')->revision);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'LEARN-STANDING-1', by: 'fixture');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'php -l src/Foo.php',
            ValidationStatus::PASSED,
            0,
            10,
            'fixture',
            implementationSnapshot: $snapshot->digest,
        );
        $reviewRoot = $this->root . '/.agent-loop/recall/LEARN-STANDING-1/reviews';
        mkdir($reviewRoot, 0o775, true);
        file_put_contents(
            $reviewRoot . '/LEARN-STANDING-1.blindspots.json',
            json_encode([
                'version' => 2,
                'task_id' => 'LEARN-STANDING-1',
                'status' => 'ok',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $exit = (new WorkflowLearningCommand($this->root))->run([
            'LEARN-STANDING-1',
            '--status', 'no_durable_learning',
            '--by', 'standing:spoofed',
        ]);

        self::assertSame(1, $exit, 'The public workflow learn command must reject the reserved actor.');
        self::assertNull(
            (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->find($run->runId),
            'A rejected reserved actor must not persist a Learning decision.',
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
