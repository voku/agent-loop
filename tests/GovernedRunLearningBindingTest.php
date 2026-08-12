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
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReportCommand;
use voku\AgentSession\SessionStore;

/**
 * A governed Run records the durable Learning repository it is governed
 * against. Without that binding the close gate and the durable projection can
 * disagree about whether a Run has a Learning close-out at all.
 */
final class GovernedRunLearningBindingTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-run-learning-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/learning-root', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testProjectionReadsTheLearningDecisionFromTheRunsOwnBinding(): void
    {
        $run = $this->prepareRun($this->root . '/learning-root');
        (new RunLearningDecisionStore($this->root . '/learning-root'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'Bound Learning root stays readable from the Run alone.',
        );

        $projected = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('learning-root', $run->learningRoot);
        self::assertSame('decided', $projected->references['learning']['state'] ?? null);
        self::assertSame('no_durable_learning', $projected->references['learning']['decision'] ?? null);
    }

    public function testDecisionInTheLayoutDefaultIsNotAttributedToARunBoundElsewhere(): void
    {
        $run = $this->prepareRun($this->root . '/learning-root');
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'Recorded in a repository this Run is not governed against.',
        );

        $projected = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('missing', $projected->references['learning']['state'] ?? null);
    }

    public function testResumingARunAgainstADifferentLearningRootIsRefused(): void
    {
        $this->prepareRun($this->root . '/learning-root');
        $contract = (new TaskContractStore($this->root))->load('ABC-123');
        $session = (new SessionStore())->all($this->root . '/.agent-loop/sessions')[0];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is governed against Learning root learning-root');

        (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/other-root');
    }

    public function testReportRefusesAnExplicitLearningRootThatDisagreesWithTheRun(): void
    {
        $this->prepareRun($this->root . '/learning-root');
        mkdir($this->root . '/other-root', 0o775, true);

        ob_start();
        $exit = (new WorkflowReportCommand($this->root))->run(['ABC-123', '--learning-root', $this->root . '/other-root']);
        ob_end_clean();

        self::assertSame(1, $exit);
    }

    private function prepareRun(string $learningRoot): \voku\AgentLoop\Run\GovernedRun
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Bind a Run to its Learning root.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        $contract = $contracts->approve('ABC-123', 'lars');
        self::assertSame(TaskContract::APPROVED, $contract->status);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');

        return (new GovernedRunStore($this->root))->prepare($contract, $session, $learningRoot);
    }

    private function rm(string $path): void
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
