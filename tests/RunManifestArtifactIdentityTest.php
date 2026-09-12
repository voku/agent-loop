<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Run\RunManifestProjector;

final class RunManifestArtifactIdentityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-artifact-identity-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testProjectedArtifactIdentityChangesWhenBytesChangeButStatKeyDoesNot(): void
    {
        $path = $this->root . '/.agent-loop/map/php-symbols.json';
        $before = '{"schema_version":"2.0","root":"/test","backend":"test","files":[],"relations":[]}';
        $after = '{"schema_version":"2.0","root":"/rest","backend":"test","files":[],"relations":[]}';
        self::assertSame(strlen($before), strlen($after));

        file_put_contents($path, $before);
        $mtime = filemtime($path);
        self::assertIsInt($mtime);

        $first = (new RunManifestProjector($this->root))->project('TASK-1');
        $firstSha = $first->references['map']['source']['sha256'] ?? null;
        self::assertIsString($firstSha);

        file_put_contents($path, $after);
        touch($path, $mtime);
        clearstatcache(true, $path);
        self::assertSame($mtime, filemtime($path));
        self::assertSame(strlen($before), filesize($path));

        $second = (new RunManifestProjector($this->root))->project('TASK-2');
        $secondSha = $second->references['map']['source']['sha256'] ?? null;
        self::assertIsString($secondSha);

        self::assertNotSame($firstSha, $secondSha);
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
