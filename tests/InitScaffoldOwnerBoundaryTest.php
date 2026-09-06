<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class InitScaffoldOwnerBoundaryTest extends TestCase
{
    public function testScaffoldDelegatesBoardBootstrapAndAvoidsOwnerPrivatePaths(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Init/InitScaffoldCommand.php');

        self::assertIsString($source);
        self::assertStringContainsString('BoardConfigurationWriter', $source);
        self::assertStringContainsString('bootstrapConventional', $source);
        self::assertStringContainsString('BoardContextResolver', $source);
        self::assertStringContainsString('repository->exists', $source);
        self::assertStringNotContainsString('/todo/kanban.config.json', $source);
        self::assertStringNotContainsString('/todo/board.md', $source);
        self::assertStringNotContainsString('/todo/cards', $source);
        self::assertStringNotContainsString('/todo/archive', $source);
        self::assertStringNotContainsString('/todo/jira/', $source);
        self::assertStringNotContainsString("/findings'", $source);
        self::assertStringNotContainsString('json_encode(', $source);
    }
}
