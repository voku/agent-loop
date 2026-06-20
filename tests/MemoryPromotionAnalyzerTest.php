<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\MemoryPromotionAnalyzer;

/**
 * @internal
 */
final class MemoryPromotionAnalyzerTest extends TestCase
{
    private function runAnalyzer(string $file): string
    {
        $analyzer = new MemoryPromotionAnalyzer(__DIR__);

        ob_start();
        $analyzer->run(['review', '--file=' . $file]);

        return (string) ob_get_clean();
    }

    public function testCountsPendingPromotionRows(): void
    {
        $output = $this->runAnalyzer(__DIR__ . '/fixtures/MEMORY.pending.md');

        // Two durable rows; "pending review" + "this file" both count as pending.
        self::assertStringContainsString('Durable repository rules: 2', $output);
        self::assertStringContainsString('Archived task rows: 3', $output);
        self::assertStringContainsString('Rows still needing promotion review: 2', $output);
        self::assertStringContainsString('## Review queue', $output);
        self::assertStringContainsString('ABC-1', $output);
        self::assertStringContainsString('ABC-3', $output);
        self::assertStringNotContainsString('ABC-2', $output);
    }

    public function testReportsNoPendingRows(): void
    {
        $output = $this->runAnalyzer(__DIR__ . '/fixtures/MEMORY.clean.md');

        self::assertStringContainsString('Rows still needing promotion review: 0', $output);
        self::assertStringContainsString('[OK] No pending promotion rows found.', $output);
    }

    public function testMissingFileReturnsError(): void
    {
        $analyzer = new MemoryPromotionAnalyzer(__DIR__);

        ob_start();
        $exit = $analyzer->run(['review', '--file=' . __DIR__ . '/fixtures/does-not-exist.md']);
        ob_end_clean();

        self::assertSame(1, $exit);
    }

    public function testHelpReturnsZero(): void
    {
        $analyzer = new MemoryPromotionAnalyzer(__DIR__);

        ob_start();
        $exit = $analyzer->run(['help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop memory', $output);
    }
}
