<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\AcceptedRiskWriter;

final class AcceptedRiskWriterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-risk-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testWritesDeterministicAcceptedRiskFile(): void
    {
        $relative = (new AcceptedRiskWriter($this->root))->write('ABC-123', 'Manual review.');

        self::assertSame('.agent-loop/risks/ABC-123.accepted-risk.md', $relative);
        self::assertSame(
            "# Accepted risk for ABC-123\n\n"
            . "Reason: Manual review.\n\n"
            . "Bypassing workflow close gates does not approve code or durable learning.\n"
            . "Human review remains required.\n",
            file_get_contents($this->root . '/' . $relative),
        );
    }

    public function testThrowsWhenAcceptedRiskPathCannotBeWritten(): void
    {
        mkdir($this->root . '/.agent-loop/risks', 0o775, true);
        mkdir($this->root . '/.agent-loop/risks/ABC-123.accepted-risk.md');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Could not write accepted-risk file: .agent-loop/risks/ABC-123.accepted-risk.md');

        (new AcceptedRiskWriter($this->root))->write('ABC-123', 'Manual review.');
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
