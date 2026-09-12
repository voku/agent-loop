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
        $this->root = sys_get_temp_dir() . '/agent-loop-artifact-identity-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testContentChangeCannotReuseArtifactIdentityWhenMtimeAndSizeStayEqual(): void
    {
        $path = $this->root . '/.agent-loop/map/php-symbols.json';
        $firstContent = '{"schema_version":"2.0","root":"/test","backend":"test","files":[],"relations":[]}';
        $secondContent = '{"schema_version":"2.0","root":"/best","backend":"test","files":[],"relations":[]}';
        self::assertSame(strlen($firstContent), strlen($secondContent));

        file_put_contents($path, $firstContent);
        clearstatcache(true, $path);
        $mtime = filemtime($path);
        self::assertIsInt($mtime);

        $first = (new RunManifestProjector($this->root))->project('TASK-1');
        $firstSha = $first->references['map']['source']['sha256'] ?? null;
        self::assertIsString($firstSha);

        file_put_contents($path, $secondContent);
        self::assertTrue(touch($path, $mtime));
        clearstatcache(true, $path);
        self::assertSame($mtime, filemtime($path));
        self::assertSame(strlen($firstContent), filesize($path));

        $second = (new RunManifestProjector($this->root))->project('TASK-2');
        $secondSha = $second->references['map']['source']['sha256'] ?? null;
        self::assertIsString($secondSha);

        self::assertNotSame(
            $firstSha,
            $secondSha,
            'Artifact identity must describe current content; path + second-resolution mtime + size is not a content identity.',
        );
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
