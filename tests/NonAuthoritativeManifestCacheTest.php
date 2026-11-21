<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;

/** @internal */
final class NonAuthoritativeManifestCacheTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-non-authoritative-manifest-' . bin2hex(random_bytes(6));
        $runRoot = $this->root . '/.agent-loop/runs/ABC-123';
        if (!mkdir($runRoot, 0o775, true) && !is_dir($runRoot)) {
            throw new RuntimeException('Unable to create manifest-cache fixture.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testExplicitStoredProjectionReadStillRejectsUnsupportedSchema(): void
    {
        $this->writeUnsupportedManifest();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported run-manifest schema version');

        (new RunManifestStore($this->root))->read('ABC-123');
    }

    public function testUnsupportedPersistedProjectionCannotBlockFreshOwnerBackedStatus(): void
    {
        $this->writeUnsupportedManifest();

        $this->assertStatusReportsStaleProjection('Unsupported run-manifest schema version');
    }

    public function testExplicitStoredProjectionReadRejectsCorruptJson(): void
    {
        $this->writeCorruptManifest();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid run manifest JSON');

        (new RunManifestStore($this->root))->read('ABC-123');
    }

    public function testCorruptPersistedProjectionIsReportedWithoutBlockingFreshOwnerBackedStatus(): void
    {
        $this->writeCorruptManifest();
        $manifestPath = $this->root . '/.agent-loop/runs/ABC-123/manifest.json';
        $before = file_get_contents($manifestPath);

        $this->assertStatusReportsStaleProjection('Invalid run manifest JSON');
        self::assertSame($before, file_get_contents($manifestPath), 'Read-only status must not repair or replace the corrupt projection.');
    }

    private function assertStatusReportsStaleProjection(string $reason): void
    {
        ob_start();
        try {
            $exit = (new WorkflowStatusCommand($this->root))->run(['ABC-123', '--format=json']);
            $output = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, 'A non-authoritative persisted projection must not veto live owner-backed status.');
        self::assertIsString($output);
        $status = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($status);

        self::assertSame('incomplete', $status['manifest']['state'] ?? null);
        self::assertSame('stale', $status['storage']['state'] ?? null);
        self::assertArrayHasKey('stored_sha256', $status['storage']);
        self::assertNull($status['storage']['stored_sha256']);
        self::assertStringContainsString($reason, (string) ($status['storage']['reason'] ?? ''));
    }

    private function writeCorruptManifest(): void
    {
        file_put_contents($this->root . '/.agent-loop/runs/ABC-123/manifest.json', '{"schema_version": "1.0", "task_id": ');
    }

    private function writeUnsupportedManifest(): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/runs/ABC-123/manifest.json',
            json_encode([
                'schema_version' => '0.0',
                'task_id' => 'ABC-123',
                'state' => 'complete',
            ], JSON_THROW_ON_ERROR),
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
