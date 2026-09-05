<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class WorkflowReportLearningHistoryOwnerBoundaryTest extends TestCase
{
    public function testReportReadsLearningOutcomeHistoryThroughOwnerRepository(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowReportCommand.php');
        self::assertIsString($source);

        self::assertStringContainsString('OutcomeRepository', $source);
        self::assertStringNotContainsString('history/outcomes.jsonl', $source);
        self::assertStringNotContainsString('FILE_IGNORE_NEW_LINES', $source);
        self::assertStringNotContainsString('json_decode($line', $source);
    }
}
