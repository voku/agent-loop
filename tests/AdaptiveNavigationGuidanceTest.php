<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class AdaptiveNavigationGuidanceTest extends TestCase
{
    public function testDisciplineUsesAdaptivePhpNavigation(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertLessThanOrEqual(8_000, strlen($skill));
        self::assertStringContainsString('Use the cheapest reliable navigation', $skill);
        self::assertStringContainsString('known files/symbols', $skill);
        self::assertStringContainsString('unknown implementation ownership', $skill);
        self::assertStringContainsString('relevant fresh Map already exists', $skill);
        self::assertStringContainsString('do not build Map merely to satisfy policy', $skill);
        self::assertStringContainsString('fall back to CLI navigation', $skill);
        self::assertStringContainsString('Do not mechanically repeat equivalent discovery', $skill);
        self::assertStringNotContainsString('Use Map first for PHP navigation', $skill);
    }

    public function testPackageDocumentationKeepsCliAndMapComplementary(): void
    {
        $info = file_get_contents(dirname(__DIR__) . '/docs/reference/agent-assets.md');

        self::assertIsString($info);
        self::assertStringContainsString('adaptive PHP navigation across CLI and agent-map', $info);
        self::assertStringContainsString('Choose navigation by the information needed', $info);
        self::assertStringContainsString('without building Map merely for policy compliance', $info);
        self::assertStringContainsString('Use Map for structural PHP questions', $info);
        self::assertStringContainsString('fall back to CLI navigation', $info);
        self::assertStringContainsString('Do not mechanically repeat equivalent discovery', $info);
    }

    public function testLearningPointsBackToAdaptivePolicyOwner(): void
    {
        $learning = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-learning/SKILL.md');

        self::assertIsString($learning);
        self::assertStringContainsString('`agent-loop-discipline` for adaptive PHP navigation', $learning);
        self::assertStringNotContainsString('`agent-loop-discipline` for map-first navigation', $learning);
    }
}
