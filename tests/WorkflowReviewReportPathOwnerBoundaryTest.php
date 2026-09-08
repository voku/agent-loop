<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class WorkflowReviewReportPathOwnerBoundaryTest extends TestCase
{
    public function testWorkflowReviewReaderUsesRecallOwnerPathProjection(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowReviewReportReader.php');

        self::assertIsString($source);
        self::assertStringContainsString('->jsonPath($taskId, $outputDirectory)', $source);
        self::assertStringNotContainsString("'/reviews/'", $source);
        self::assertStringNotContainsString('blindspots.json', $source);
    }
}
