<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\ProjectLayout;

/** @internal */
final class ProjectLayoutTest extends TestCase
{
    public function testUsesCompactLayoutByDefault(): void
    {
        $root = $this->root();

        try {
            $layout = new ProjectLayout($root);

            self::assertTrue($layout->isCompact());
            self::assertSame($root . '/.agent-loop', $layout->stateRoot());
            self::assertSame($root . '/.agent-loop', $layout->boardRoot());
            self::assertSame($root . '/.agent-loop/tasks', $layout->tasksRoot());
            self::assertSame($root . '/.agent-loop/sessions', $layout->sessionsRoot());
            self::assertSame($root . '/.agent-loop/learning', $layout->learningRoot());
            self::assertSame($root . '/.agent-loop/recall', $layout->recallRoot());
            self::assertSame($root . '/.agent-loop/map/php-symbols.json', $layout->mapIndex());
            self::assertSame($root . '/.agent-loop/map/search.sqlite', $layout->mapSearchIndex());
        } finally {
            $this->remove($root);
        }
    }

    public function testExistingConfigWithoutLayoutStillUsesCompactDefault(): void
    {
        $root = $this->root();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', "{\n  \"version\": 1\n}\n");

        try {
            $layout = new ProjectLayout($root);

            self::assertTrue($layout->isCompact());
            self::assertSame($root . '/.agent-loop/tasks', $layout->tasksRoot());
            self::assertSame($root . '/.agent-loop/sessions', $layout->sessionsRoot());
            self::assertSame($root . '/.agent-loop/learning', $layout->learningRoot());
        } finally {
            $this->remove($root);
        }
    }

    public function testExplicitLegacyLayoutKeepsHistoricalPaths(): void
    {
        $root = $this->root();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents($root . '/.agent-loop/init.json', "{\n  \"version\": 1,\n  \"layout\": \"legacy\"\n}\n");

        try {
            $layout = new ProjectLayout($root);

            self::assertFalse($layout->isCompact());
            self::assertSame($root, $layout->boardRoot());
            self::assertSame($root . '/tasks', $layout->tasksRoot());
            self::assertSame($root . '/session_plan', $layout->sessionsRoot());
            self::assertSame($root . '/recall', $layout->recallRoot());
            self::assertSame($root . '/.agent-map/php-symbols.json', $layout->mapIndex());
            self::assertSame($root . '/.agent-map/search.sqlite', $layout->mapSearchIndex());
        } finally {
            $this->remove($root);
        }
    }

    public function testExplicitRecallRootStillWinsInCompactLayout(): void
    {
        $root = $this->root();
        mkdir($root . '/.agent-loop', 0o775, true);
        file_put_contents(
            $root . '/.agent-loop/init.json',
            "{\n  \"version\": 1,\n  \"layout\": \"compact\",\n  \"paths\": {\n    \"recall_root\": \"custom/recall\"\n  }\n}\n",
        );

        try {
            self::assertSame($root . '/custom/recall', (new ProjectLayout($root))->recallRoot());
        } finally {
            $this->remove($root);
        }
    }

    private function root(): string
    {
        $root = sys_get_temp_dir() . '/agent-loop-layout-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        return $root;
    }

    private function remove(string $path): void
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
