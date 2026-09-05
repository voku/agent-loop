<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunManifestProjector;

final class RunManifestKanbanOwnerBoundaryTest extends TestCase
{
    public function testManifestUsesKanbanOwnerResolutionWithoutPrivateLayoutKnowledge(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Run/RunManifestProjector.php');
        self::assertIsString($source);

        self::assertStringContainsString('BoardContextResolver', $source);
        self::assertStringContainsString('resolveOptionalWithProvenance', $source);
        self::assertStringContainsString('BoardContextResolution', $source);
        self::assertStringContainsString('resolveAll', $source);
        self::assertStringNotContainsString('BoardContextFactory', $source);
        self::assertStringNotContainsString('kanban.config.json', $source);
        self::assertStringNotContainsString('board.md', $source);
        self::assertStringNotContainsString('glob(', $source);
    }

    public function testManifestSelectsSecondaryBoardAndProjectsOwnerConfigurationProvenance(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-run-manifest-kanban-owner-' . bin2hex(random_bytes(8));
        mkdir($root . '/.agent-loop/todo/abc', 0o775, true);
        mkdir($root . '/.agent-loop/todo/xyz', 0o775, true);
        file_put_contents(
            $root . '/.agent-loop/todo/kanban.config.json',
            json_encode([
                'defaultBoard' => 'abc',
                'boards' => [
                    ['id' => 'abc', 'projectPrefix' => 'ABC', 'cardDirectory' => 'todo/abc'],
                    ['id' => 'xyz', 'projectPrefix' => 'XYZ', 'cardDirectory' => 'todo/xyz'],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );
        file_put_contents($root . '/.agent-loop/todo/xyz/XYZ-1.md', <<<'MD'
# XYZ-1: Secondary board task

- **Ticket:** XYZ-1
- **Lane:** READY
- **Status:** Selected

## Agent Task Brief

Prove that the manifest consumes the owner-resolved multi-board context.
MD
            . "\n");

        try {
            $manifest = (new RunManifestProjector($root))->project('XYZ-1');

            self::assertSame('linked', $manifest->references['board']['state']);
            self::assertSame('XYZ-1', $manifest->references['board']['card_id']);
            self::assertSame('READY', $manifest->references['board']['lane']);
            self::assertSame('.agent-loop/todo/xyz/XYZ-1.md', $manifest->references['board']['source']['path']);
            self::assertSame('json', $manifest->references['board']['configuration']['mode']);
            self::assertSame(
                '.agent-loop/todo/kanban.config.json',
                $manifest->references['board']['configuration']['source']['path'],
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $path . '/' . $entry;
            is_dir($fullPath) ? $this->removeDirectory($fullPath) : unlink($fullPath);
        }

        rmdir($path);
    }
}
