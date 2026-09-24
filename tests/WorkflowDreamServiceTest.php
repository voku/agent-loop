<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\EvolutionDecisionType;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\WorkflowDreamService;

final class WorkflowDreamServiceTest extends TestCase
{
    private string $root;
    private string $learningRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-dream-' . bin2hex(random_bytes(4));
        $this->learningRoot = (new ProjectLayout($this->root))->learningRoot();
        self::assertTrue(mkdir($this->learningRoot . '/findings/validated', 0o775, true));
        self::assertTrue(mkdir($this->root . '/src', 0o775, true));
        file_put_contents($this->root . '/src/Example.php', "<?php\n");
        // One validated lesson recurring on two independent tasks: the smallest
        // corpus agent-learning's Dream turns into a promotion candidate.
        $this->writeFinding('finding.2026-06-01.001', 'TASK-1');
        $this->writeFinding('finding.2026-06-02.001', 'TASK-2');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testPreviewRunsDreamAgainstTheRepositoryLearningRootAndWritesNothing(): void
    {
        $report = (new WorkflowDreamService($this->root))->preview();

        self::assertSame($this->learningRoot, $report->learningRoot);
        self::assertFalse($report->wroteCandidates());
        self::assertSame([], glob($this->learningRoot . '/proposals/candidate/*.json') ?: []);
        self::assertContains(
            EvolutionDecisionType::PROMOTION_CANDIDATE,
            array_map(static fn ($decision) => $decision->type, $report->reviewableDecisions()),
        );
    }

    public function testWritingCandidatesLeavesThemForHumanReview(): void
    {
        $service = new WorkflowDreamService($this->root);

        $report = $service->writeCandidates();

        self::assertTrue($report->wroteCandidates());
        self::assertCount(
            count($report->outcome->writtenCandidateIds),
            glob($this->learningRoot . '/proposals/candidate/*.json') ?: [],
        );
        self::assertSame([], glob($this->learningRoot . '/proposals/approved/*.json') ?: [], 'Loop never approves Dream output');
        self::assertFalse($service->writeCandidates()->wroteCandidates(), 'A written candidate is suppressed on the next run');
    }

    private function writeFinding(string $id, string $taskId): void
    {
        file_put_contents($this->learningRoot . '/findings/validated/' . $id . '.json', json_encode([
            'id' => $id,
            'task_id' => $taskId,
            'session' => 'session.' . $taskId,
            'created_at' => '2026-06-01T00:00:00+00:00',
            'created_by' => 'tester',
            'scope' => ['src'],
            'observation' => 'Observation is concrete.',
            'evidence' => [['type' => 'file_reference', 'path' => 'src/Example.php', 'line' => 1]],
            'hypothesis' => 'Hypothesis is distinct.',
            'validated_conclusion' => 'Conclusion is validated.',
            'confidence' => 'high',
            'validation_status' => 'validated',
            'status' => 'validated',
            'sensitivity' => 'public',
            'pattern_key' => 'loop.dream',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
