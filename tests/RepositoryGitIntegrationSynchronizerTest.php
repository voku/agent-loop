<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\RepositoryGitIntegrationSynchronizer;

final class RepositoryGitIntegrationSynchronizerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-git-integration-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/githooks.json', '{}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRefusesToWriteThroughASymlinkedHookTarget(): void
    {
        mkdir($this->root . '/.githooks', 0o775, true);
        $escape = sys_get_temp_dir() . '/agent-loop-git-integration-escape-' . bin2hex(random_bytes(6));
        symlink($escape, $this->root . '/.githooks/post-merge');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to write through a symlink');

            (new RepositoryGitIntegrationSynchronizer($this->root))->syncDeclared();
        } finally {
            self::assertFileDoesNotExist($escape);
            @unlink($this->root . '/.githooks/post-merge');
        }
    }

    public function testRefusesToWriteThroughASymlinkedHookDirectory(): void
    {
        $escape = sys_get_temp_dir() . '/agent-loop-git-integration-escape-dir-' . bin2hex(random_bytes(6));
        mkdir($escape, 0o775, true);
        symlink($escape, $this->root . '/.githooks');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to write through a symlink');

            (new RepositoryGitIntegrationSynchronizer($this->root))->syncDeclared();
        } finally {
            self::assertSame([], array_diff(scandir($escape) ?: [], ['.', '..']));
            @unlink($this->root . '/.githooks');
            $this->removeDirectory($escape);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (is_link($path)) {
                unlink($path);
            }

            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isLink()) {
                unlink($item->getPathname());
                continue;
            }
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
