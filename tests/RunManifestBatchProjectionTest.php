<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentSession\SessionStore;

final class RunManifestBatchProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-run-batch-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testBatchProjectionMatchesSingleProjectionSessionSemantics(): void
    {
        $store = new SessionStore();
        $sessionsRoot = $this->root . '/.agent-loop/sessions';
        $store->create($sessionsRoot, 'TASK-A', slug: 'experiment', by: 'lars', ephemeral: true);
        $governed = $store->create($sessionsRoot, 'TASK-A', slug: 'governed', by: 'lars');
        $ephemeral = $store->create($sessionsRoot, 'TASK-B', slug: 'experiment', by: 'lars', ephemeral: true);

        $singleA = (new RunManifestProjector($this->root))->project('TASK-A');
        $singleB = (new RunManifestProjector($this->root))->project('TASK-B');

        $batch = (new RunManifestProjector($this->root))->projectMany(['TASK-A', 'TASK-B']);

        self::assertCount(2, $batch);
        self::assertSame($singleA->toArray(), $batch[0]->toArray());
        self::assertSame($singleB->toArray(), $batch[1]->toArray());
        self::assertSame($governed->id, $batch[0]->references['session']['session_id'] ?? null);
        self::assertSame($ephemeral->id, $batch[1]->references['session']['session_id'] ?? null);
    }

    public function testBatchCachesDoNotLeakAcrossCallsWhenMtimeAndSizeStayEqual(): void
    {
        $path = $this->root . '/.agent-loop/map/php-symbols.json';
        $firstContent = '{"schema_version":"2.0","root":"/test","backend":"test","files":[],"relations":[]}';
        $secondContent = '{"schema_version":"2.0","root":"/best","backend":"test","files":[],"relations":[]}';
        self::assertSame(strlen($firstContent), strlen($secondContent));

        file_put_contents($path, $firstContent);
        clearstatcache(true, $path);
        $mtime = filemtime($path);
        self::assertIsInt($mtime);

        $projector = new RunManifestProjector($this->root);
        $first = $projector->projectMany(['TASK-A'])[0];
        $firstSha = $first->references['map']['source']['sha256'] ?? null;
        self::assertIsString($firstSha);

        file_put_contents($path, $secondContent);
        self::assertTrue(touch($path, $mtime));
        clearstatcache(true, $path);
        self::assertSame($mtime, filemtime($path));
        self::assertSame(strlen($firstContent), filesize($path));

        $second = $projector->projectMany(['TASK-B'])[0];
        $secondSha = $second->references['map']['source']['sha256'] ?? null;
        self::assertIsString($secondSha);

        self::assertNotSame($firstSha, $secondSha);
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
