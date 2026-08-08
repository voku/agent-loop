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
    /**
     * @return array{exit: int, output: string}
     */
    private function runAnalyzer(string $file, string $command = 'review'): array
    {
        $analyzer = new MemoryPromotionAnalyzer(__DIR__);

        ob_start();
        $exit = $analyzer->run([$command, '--file=' . $file]);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    public function testCountsPendingPromotionRows(): void
    {
        $result = $this->runAnalyzer(__DIR__ . '/fixtures/MEMORY.pending.md');

        self::assertSame(0, $result['exit']);
        // Two durable rows; "pending review" + "this file" both count as pending.
        self::assertStringContainsString('Durable repository rules: 2', $result['output']);
        self::assertStringContainsString('Archived task rows: 3', $result['output']);
        self::assertStringContainsString('Rows still needing promotion review: 2', $result['output']);
        self::assertStringContainsString('## Review queue', $result['output']);
        self::assertStringContainsString('ABC-1', $result['output']);
        self::assertStringContainsString('ABC-3', $result['output']);
        self::assertStringNotContainsString('ABC-2', $result['output']);
    }

    public function testReportsNoPendingRows(): void
    {
        $result = $this->runAnalyzer(__DIR__ . '/fixtures/MEMORY.clean.md');

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Rows still needing promotion review: 0', $result['output']);
        self::assertStringContainsString('[OK] No pending promotion rows found.', $result['output']);
    }

    public function testValidateUsesTheSameSuccessfulParser(): void
    {
        $result = $this->runAnalyzer(__DIR__ . '/fixtures/MEMORY.clean.md', 'validate');

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] MEMORY structure is valid: 1 durable rule(s), 2 archived task row(s).', $result['output']);
    }

    public function testMissingDurableTableHeaderFailsInsteadOfReportingZeroRows(): void
    {
        $this->assertInvalidMemory(<<<'MD'
        # MEMORY

        ## Durable repository rules

        | Topic | Durable rule | Canonical home |
        | --- | --- | --- |
        | Testing | Test it. | tests |
        MD);
    }

    public function testMalformedSeparatorFails(): void
    {
        $this->assertInvalidMemory(<<<'MD'
        # MEMORY

        ## Durable repository rules

        | Subject | Durable rule | Canonical home |
        | --- | nope | --- |
        | Testing | Test it. | tests |
        MD);
    }

    public function testMalformedArchiveRowFails(): void
    {
        $this->assertInvalidMemory(<<<'MD'
        # MEMORY

        ## Durable repository rules

        | Subject | Durable rule | Canonical home |
        | --- | --- | --- |
        | Testing | Test it. | tests |

        ## Archive

        | Archived on | Task | Summary | Archive reason | Durable lesson candidate | Promoted to |
        | --- | --- | --- | --- | --- | --- |
        | 2026-08-08 | TASK-1 | Summary | done | missing last column |
        MD);
    }

    public function testDuplicateDurableSubjectsFail(): void
    {
        $this->assertInvalidMemory(<<<'MD'
        # MEMORY

        ## Durable repository rules

        | Subject | Durable rule | Canonical home |
        | --- | --- | --- |
        | Testing | Test it. | tests |
        | testing | Test it differently. | tests |
        MD);
    }

    public function testMissingFileReturnsError(): void
    {
        $result = $this->runAnalyzer(__DIR__ . '/fixtures/does-not-exist.md');

        self::assertSame(1, $result['exit']);
    }

    public function testHelpReturnsZero(): void
    {
        $analyzer = new MemoryPromotionAnalyzer(__DIR__);

        ob_start();
        $exit = $analyzer->run(['help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop memory', $output);
        self::assertStringContainsString('memory validate', $output);
        self::assertStringContainsString('memory review', $output);
    }

    private function assertInvalidMemory(string $content): void
    {
        $file = tempnam(sys_get_temp_dir(), 'agent-loop-memory-');
        self::assertIsString($file);
        file_put_contents($file, $content);

        try {
            self::assertSame(1, $this->runAnalyzer($file, 'validate')['exit']);
            self::assertSame(1, $this->runAnalyzer($file, 'review')['exit']);
        } finally {
            unlink($file);
        }
    }
}
