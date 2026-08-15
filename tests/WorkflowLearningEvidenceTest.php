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
        $contract = $contracts->approve('LEARN-STALE-1', 'fixture');
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
        $contract = $contracts->approve('LEARN-FAILED-1', 'fixture');
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
                'status' => 'fail',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
            ], JSON_THROW_ON_ERROR),
        );

        $exit = (new WorkflowLearningCommand($this->root))->run([
            'LEARN-FAILED-1',
            '--status', 'no_durable_learning',
            '--by', 'fixture',
            '--reason', 'The failed checks are current evidence and can inform the Run learning close-out.',
        ]);

        self::assertSame(0, $exit, 'Learning must remain available for current failed validation/review evidence.');
        self::assertNotNull(
            (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->find($run->runId),
            'Current failed evidence must still be bindable to a durable Run learning decision.',
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
