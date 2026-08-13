<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class RealSimplePhpSelectiveAdaptationFixtureTest extends TestCase
{
    public function testPullRequest99PreservesTheSelectiveAdaptationPins(): void
    {
        $fixture = file_get_contents(__DIR__ . '/fixtures/real-upstream-adaptation/simple-php-code-parser-99.json');
        self::assertIsString($fixture);

        foreach (['c5e6275cc2c685cdc00cec7af0da9ee5a5b69014', 'b572272b111372d1709bceb027deeb341061e24e', 'd7db905e4ce183b0a945f7b9808844dfa3922641'] as $sha) {
            self::assertStringContainsString($sha, $fixture);
        }
        self::assertStringContainsString('"production_mutation_required": true', $fixture);
        self::assertStringContainsString('"preserve_package_identity": true', $fixture);
        self::assertStringContainsString('"preserve_namespace": true', $fixture);
    }
}
