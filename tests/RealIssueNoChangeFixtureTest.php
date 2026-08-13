<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class RealIssueNoChangeFixtureTest extends TestCase
{
    public function testSimplePhpCodeParser105PreservesTheNoChangeEvidence(): void
    {
        $fixture = file_get_contents(__DIR__ . '/fixtures/real-issue-no-change/simple-php-code-parser-105.json');
        self::assertIsString($fixture);

        self::assertStringContainsString('"issue": 105', $fixture);
        self::assertStringContainsString('"run_id": 31659131414', $fixture);
        self::assertStringContainsString('"expected_decision": "NO_PRODUCTION_CHANGE_REQUIRED"', $fixture);
        self::assertStringContainsString('ParserEntryPointsTest::testGetAstResolvesNamesAndKeepsFilePositions', $fixture);
        self::assertStringContainsString('PhpCodeParser::getAstFromString', $fixture);
        self::assertStringContainsString('"relation": "calls"', $fixture);
        self::assertStringContainsString('"production_mutation_required": false', $fixture);
    }
}
