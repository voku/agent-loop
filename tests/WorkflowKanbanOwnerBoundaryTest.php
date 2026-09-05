<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowKanbanContextProjector;

final class WorkflowKanbanOwnerBoundaryTest extends TestCase
{
    public function testProjectorUsesKanbanOwnerResolutionWithoutPrivateConfigPaths(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowKanbanContextProjector.php');
        self::assertIsString($source);

        self::assertStringContainsString('BoardContextResolver', $source);
        self::assertStringContainsString('resolveOptional', $source);
        self::assertStringNotContainsString('kanban.config.json', $source);
        self::assertStringNotContainsString('BoardConfig::fromJsonFile', $source);
        self::assertStringNotContainsString('new MarkdownCardRepository', $source);
    }

    public function testProjectorCanUseMetadataOnlyBoardResolvedByOwner(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-kanban-owner-' . bin2hex(random_bytes(8));
        mkdir($root . '/.agent-loop/todo/cards', 0o775, true);
        file_put_contents(
            $root . '/.agent-loop/todo/board.md',
            "# Board\n\n- **Project prefix:** META\n\n## Work\n",
        );
        file_put_contents(
            $root . '/.agent-loop/todo/cards/META-1.md',
            "# META-1: Owner-resolved task\n\n- **Ticket:** META-1\n- **Lane:** READY\n- **Status:** Selected\n- **Summary:** S\n- **Next:** N\n",
        );

        try {
            $projection = (new WorkflowKanbanContextProjector($root))->project('META-1');

            self::assertNotNull($projection);
            self::assertSame('Owner-resolved task', $projection->title);
            self::assertSame('todo/cards/META-1.md', $projection->sourcePath);
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

            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }

        rmdir($path);
    }
}
