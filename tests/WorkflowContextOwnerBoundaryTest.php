<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class WorkflowContextOwnerBoundaryTest extends TestCase
{
    public function testSessionContextUsesTheSessionOwnerProjection(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Workflow/WorkflowContextCommand.php');
        self::assertIsString($source);

        self::assertStringContainsString('SessionHandoffProjector', $source);
        self::assertStringNotContainsString("'decisions.md'", $source);
        self::assertStringNotContainsString("'assumptions.md'", $source);
        self::assertStringNotContainsString('file_get_contents($session->path', $source);
    }
}
