<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\RunManifest;
use voku\AgentLoop\Run\RunManifestStore;

final class RunManifestStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-run-store-' . bin2hex(random_bytes(4));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testEquivalentProjectionsProduceByteStableJson(): void
    {
        $left = new RunManifest(
            taskId: 'ABC-123',
            runId: 'session:one',
            mode: 'governed',
            state: 'incomplete',
            references: [
                'session' => ['state' => 'active', 'owner' => 'agent-session'],
                'board' => ['state' => 'not_configured', 'owner' => 'agent-kanban'],
            ],
            disagreements: [],
            nextAction: 'agent-loop workflow approve ABC-123 --by <human-actor>',
        );
        $right = new RunManifest(
            taskId: 'ABC-123',
            runId: 'session:one',
            mode: 'governed',
            state: 'incomplete',
            references: [
                'board' => ['owner' => 'agent-kanban', 'state' => 'not_configured'],
                'session' => ['owner' => 'agent-session', 'state' => 'active'],
            ],
            disagreements: [],
            nextAction: 'agent-loop workflow approve ABC-123 --by <human-actor>',
        );

        self::assertSame($left->toJson(), $right->toJson());
    }

    public function testStoreReportsMissingCurrentAndStaleWithoutLeavingTemporaryFiles(): void
    {
        $store = new RunManifestStore($this->root);
        $manifest = $this->manifest('first');

        self::assertSame('missing', $store->status($manifest)['state']);
        $path = $store->write($manifest);
        self::assertFileExists($path);
        self::assertSame('current', $store->status($manifest)['state']);
        self::assertSame('stale', $store->status($this->manifest('second'))['state']);
        self::assertSame($manifest->toArray(), $store->read('ABC-123'));
        self::assertSame([], glob($path . '.tmp-*') ?: []);
    }

    private function manifest(string $nextAction): RunManifest
    {
        return new RunManifest(
            taskId: 'ABC-123',
            runId: 'task:ABC-123:legacy',
            mode: 'legacy_inferred',
            state: 'incomplete',
            references: ['session' => ['owner' => 'agent-session', 'state' => 'missing']],
            disagreements: [],
            nextAction: $nextAction,
        );
    }

    private function rm(string $dir): void
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
