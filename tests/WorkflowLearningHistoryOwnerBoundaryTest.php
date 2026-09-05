<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class WorkflowLearningHistoryOwnerBoundaryTest extends TestCase
{
    public function testCloseReadinessUsesLearningOwnerRepositories(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowCloseReadinessInspector.php');
        self::assertIsString($source);

        self::assertStringContainsString('GuidanceOutcomeEventRepository', $source);
        self::assertStringContainsString('RecallSelectionEventRepository', $source);
        self::assertStringNotContainsString("'history/outcomes.jsonl'", $source);
        self::assertStringNotContainsString("'history/recall-selections.jsonl'", $source);
    }

    public function testWorkflowReportUsesLearningOutcomeRepository(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowReportCommand.php');
        self::assertIsString($source);

        self::assertStringContainsString('OutcomeRepository', $source);
        self::assertStringNotContainsString("'/history/outcomes.jsonl'", $source);
    }
}
