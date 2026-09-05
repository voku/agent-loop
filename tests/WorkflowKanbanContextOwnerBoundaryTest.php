<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class WorkflowKanbanContextOwnerBoundaryTest extends TestCase
{
    public function testWorkflowProjectorUsesKanbanOwnerContextInsteadOfPrivatePaths(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../src/Workflow/WorkflowKanbanContextProjector.php');

        self::assertStringContainsString('BoardContextResolver', $source);
        self::assertStringNotContainsString('kanban.config.json', $source);
        self::assertStringNotContainsString('BoardConfig', $source);
        self::assertStringNotContainsString('MarkdownCardRepository', $source);
    }
}
