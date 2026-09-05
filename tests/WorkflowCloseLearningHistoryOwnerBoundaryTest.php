<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class WorkflowCloseLearningHistoryOwnerBoundaryTest extends TestCase
{
    public function testCloseReadsLearningHistoryOnlyThroughOwnerRepositories(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowCloseReadinessInspector.php');
        self::assertIsString($source);

        self::assertStringContainsString('GuidanceOutcomeEventRepository', $source);
        self::assertStringContainsString('RecallSelectionEventRepository', $source);
        self::assertStringNotContainsString('history/outcomes.jsonl', $source);
        self::assertStringNotContainsString('history/recall-selections.jsonl', $source);
        self::assertStringNotContainsString("\$outcome['task_id']", $source);
        self::assertStringNotContainsString("\$event['outcome_withheld_reason']", $source);
    }
}
