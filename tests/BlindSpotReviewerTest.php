<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Review\BlindSpotReviewer;
use voku\AgentLoop\Review\ReviewReportWriter;

/** @internal */
final class BlindSpotReviewerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-blindspots-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMissingRecallProducesFailFindingAndStatusFail(): void
    {
        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertSame('fail', $report->status());
        self::assertSame('missing_recall', $report->findings[0]->id);
    }

    public function testExistingRecallWithValidationCanBeOk(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/ABC-123.md', "Task ABC-123\nPHPStan passed.\nagent-loop review blindspots ABC-123 found no blocking issue.\n");

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertSame('ok', $report->status());
        self::assertSame([], $report->findings);
    }

    public function testSessionDirectoryRelatedByMetadataIncludesCheckpointText(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/2026-06-28-work/session.json', '{"task_id":"ABC-123"}');
        $this->write('session_plan/2026-06-28-work/checkpoints/001-validation.md', "# Validation\nPHPStan passed.\nagent-loop review blindspots ABC-123 was checked.\n");

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertSame('ok', $report->status());
        self::assertSame([], $report->findings);
    }

    public function testMissingValidationCheckpointProducesWarn(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/ABC-123.md', 'Task ABC-123 only.');

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertSame('warn', $report->status());
        self::assertContains('missing_validation_checkpoint', array_map(static fn ($finding): string => $finding->id, $report->findings));
    }

    public function testMissingReviewCheckpointProducesWarn(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/ABC-123.md', "ABC-123\nPHPUnit passed\n");

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertSame('warn', $report->status());
        self::assertContains('missing_review_checkpoint', array_map(static fn ($finding): string => $finding->id, $report->findings));
    }

    public function testTokenNoiseMarkersProduceInfo(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/ABC-123.md', "ABC-123\nPHPUnit passed\nagent-loop review blindspots ABC-123\ndocker compose logs\nnpm install\n");

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertContains('token_noise_risk', array_map(static fn ($finding): string => $finding->id, $report->findings));
        self::assertSame('ok', $report->status());
    }

    public function testSessionTemplatePlaceholdersDoNotTriggerSecurityWarning(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/2026-06-28-work/session.json', '{"task_id":"ABC-123"}');
        $this->write('session_plan/2026-06-28-work/plan.md', "# Plan: ABC-123\n\n## Constraints\n\n- *boundaries that must hold (scope, permissions, types, no unrelated migration)*\n");
        $this->write('session_plan/2026-06-28-work/checkpoints/001-validation.md', "# Validation\nPHPStan passed.\nagent-loop review blindspots ABC-123 was checked.\n");

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertSame('ok', $report->status());
        self::assertSame([], $report->findings);
    }

    public function testSecurityMarkersProduceWarn(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/ABC-123.md', "ABC-123\nPHPUnit passed\nagent-loop review blindspots ABC-123\nLogin permission migration.\n");

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertSame('warn', $report->status());
        self::assertContains('security_sensitive_area', array_map(static fn ($finding): string => $finding->id, $report->findings));
    }

    public function testMemoryFileProducesInfo(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('session_plan/ABC-123.md', "ABC-123\ntests passed\nagent-loop review blindspots ABC-123\n");
        $this->write('MEMORY.md', '# Memory');

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');

        self::assertContains('memory_promotion_review_available', array_map(static fn ($finding): string => $finding->id, $report->findings));
    }

    public function testReportWriterWritesValidDeterministicJsonAndMarkdown(): void
    {
        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');
        (new ReviewReportWriter($this->root))->write($report);

        $jsonPath = $this->root . '/.agent-loop/reviews/ABC-123.blindspots.json';
        $markdownPath = $this->root . '/.agent-loop/reviews/ABC-123.blindspots.md';
        $promptPath = $this->root . '/.agent-loop/reviews/ABC-123.blindspots.prompt.md';

        self::assertFileExists($jsonPath);
        self::assertFileExists($markdownPath);
        self::assertFileExists($promptPath);
        self::assertSame(json_decode((string) file_get_contents($jsonPath), true), json_decode((string) file_get_contents($jsonPath), true));
        self::assertStringContainsString('"task_id": "ABC-123"', (string) file_get_contents($jsonPath));
        self::assertStringContainsString('# Blind-spot review for ABC-123', (string) file_get_contents($markdownPath));
        self::assertStringContainsString('# L2 blind-spot analysis prompt for ABC-123', (string) file_get_contents($promptPath));
    }

    public function testL2PromptUsesWorkflowAndBoardArtifacts(): void
    {
        $this->write('recall/ABC-123/meta.json', '{}');
        $this->write('recall/ABC-123/validation-plan.md', 'Validate src/Review/BlindSpotReviewer.php.');
        $this->write('session_plan/2026-06-28-work/session.json', '{"task_id":"ABC-123"}');
        $this->write('session_plan/2026-06-28-work/checkpoints/001-validation.md', "# Validation\nPHPStan passed.\nagent-loop review blindspots ABC-123 was checked.\n");
        $this->write('todo/cards/ABC-123.md', '# ABC-123 Board card');

        $report = (new BlindSpotReviewer($this->root))->review('ABC-123');
        (new ReviewReportWriter($this->root))->write($report);

        $prompt = (string) file_get_contents($this->root . '/.agent-loop/reviews/ABC-123.blindspots.prompt.md');

        self::assertStringContainsString('## Analysis phases', $prompt);
        self::assertStringContainsString('todo/cards/ABC-123.md', $prompt);
        self::assertStringContainsString('recall/ABC-123/validation-plan.md', $prompt);
        self::assertStringContainsString('session_plan/2026-06-28-work/checkpoints/001-validation.md', $prompt);
        self::assertStringContainsString('Close readiness', $prompt);
    }

    public function testPathTraversalTaskIdIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new BlindSpotReviewer($this->root))->review('../ABC-123');
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->root . '/' . $relative;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($path, $content);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
