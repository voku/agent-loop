<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunManifestProjector;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;
use voku\AgentSession\SessionStore;

final class WorkflowStatusCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-status-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testStatusNamesDurableLifecycleWithoutWritingAnything(): void
    {
        $before = $this->files();

        [$exit, $out] = $this->statusOf('ABC-123');

        self::assertSame(0, $exit);
        foreach ([
            'Board:',
            'Session:',
            'Contract:',
            'Approval:',
            'Map:',
            'Search index:',
            'Recall:',
            'Execution contract:',
            'Verification:',
            'Review:',
            'Learning:',
            'Outcome lineage:',
        ] as $stage) {
            self::assertStringContainsString($stage, $out);
        }
        self::assertStringNotContainsString('Work brief:', $out);
        self::assertStringContainsString('workflow plan ABC-123', $out);
        self::assertSame($before, $this->files(), 'status is read-only');
    }

    public function testNextCommandFollowsOwnerArtifacts(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        self::assertStringContainsString('workflow approve ABC-123', $this->statusText('ABC-123'));

        $contract = $contracts->approve('ABC-123', 'lars');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');
        $approved = $this->statusText('ABC-123');
        self::assertStringContainsString('Contract:', $approved);
        self::assertStringContainsString('revision 1', $approved);
        self::assertStringContainsString('revision 1 by lars', $approved);
        self::assertStringContainsString('workflow approve ABC-123', $approved);

        $this->writeRecall();
        self::assertStringContainsString('review blindspots ABC-123', $this->statusText('ABC-123'));

        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
            json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR),
        );
        self::assertStringContainsString('workflow learn ABC-123', $this->statusText('ABC-123'));

        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable learning for status fixture.',
        );
        self::assertStringContainsString('workflow close ABC-123 --status done', $this->statusText('ABC-123'));
    }

    public function testEphemeralSessionIsExperimentAndAsksToBeClosed(): void
    {
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars', ephemeral: true);

        $out = $this->statusText('ABC-123');

        self::assertStringContainsString('experiment', $out);
        self::assertStringContainsString('ephemeral', $out);
        self::assertStringContainsString('session close ' . $session->id, $out);
    }

    public function testUnreadableReviewBlocksStatusInsteadOfMasqueradingAsProgress(): void
    {
        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json', '{');

        [$exit, $out] = $this->statusOf('ABC-123');

        self::assertSame(2, $exit);
        self::assertStringContainsString('blocked', $out);
        self::assertStringContainsString('review.report_invalid', $out);
    }

    public function testJsonStatusReportsPersistedProjectionFreshness(): void
    {
        $projector = new GovernedRunManifestProjector($this->root);
        $store = new RunManifestStore($this->root);
        $store->write($projector->project('ABC-123'));

        [$currentExit, $currentOutput] = $this->statusOf('ABC-123', ['--format=json']);
        $current = json_decode($currentOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $currentExit);
        self::assertSame('current', $current['storage']['state'] ?? null);
        self::assertSame('legacy_inferred', $current['manifest']['mode'] ?? null);

        (new TaskContractStore($this->root))->create(
            'ABC-123',
            'Create a real planned lifecycle.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'lars',
        );
        [$staleExit, $staleOutput] = $this->statusOf('ABC-123', ['--format', 'json']);
        $stale = json_decode($staleOutput, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(0, $staleExit);
        self::assertSame('stale', $stale['storage']['state'] ?? null);
        self::assertSame('planned', $stale['manifest']['mode'] ?? null);
    }

    private function writeRecall(): void
    {
        mkdir($this->root . '/.agent-loop/recall/ABC-123', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'task_id' => 'ABC-123',
                'compilation_id' => 'status-fixture',
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
    }

    /**

     * @param list<string> $options

     * @return array{0: int, 1: string}

     */
    private function statusOf(string $taskId, array $options = []): array
    {
        ob_start();
        $exit = (new WorkflowStatusCommand($this->root))->run([$taskId, ...$options]);
        $out = (string) ob_get_clean();

        return [$exit, $out];
    }

    private function statusText(string $taskId): string
    {
        [$exit, $out] = $this->statusOf($taskId);
        self::assertSame(0, $exit, $out);

        return $out;
    }

    /** @return list<string> */
    private function files(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $files[] = $file->getPathname();
        }
        sort($files);

        return $files;
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
