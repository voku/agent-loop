<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class AdaptiveNavigationGuidanceTest extends TestCase
{
    public function testAlwaysOnDisciplineDoesNotOwnAdaptiveNavigation(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringNotContainsString('## Navigate Before Editing', $skill);
        self::assertStringNotContainsString('agent-loop map query', $skill);
        self::assertStringNotContainsString('map scope', $skill);
        self::assertStringNotContainsString('map context', $skill);
    }

    public function testInvestigateOwnsNamedPhpIdentityRouting(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-investigate/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString("map scope '<symbol>'", $skill);
        self::assertStringContainsString("map context '<symbol>'", $skill);
        self::assertStringContainsString(
            'Do not use text search to rediscover a PHP identity that `scope` already resolves.',
            $skill,
        );
        self::assertStringNotContainsString('known files/symbols', $skill);
    }

    public function testPackageDocumentationKeepsCliAndMapComplementary(): void
    {
        $info = file_get_contents(dirname(__DIR__) . '/docs/reference/agent-assets.md');

        self::assertIsString($info);
        self::assertStringContainsString('PHP/Map navigation -> `agent-loop-investigate`', $info);
        self::assertStringContainsString('Choose navigation by the information needed', $info);
        self::assertStringContainsString('without building Map merely for policy compliance', $info);
        self::assertStringContainsString('Use Map for structural PHP questions', $info);
        self::assertStringContainsString('fall back to CLI navigation', $info);
        self::assertStringContainsString('Do not mechanically repeat equivalent discovery', $info);
        self::assertStringContainsString('prefer a governed Map plan for a supported rename, removal or move', $info);
    }

    public function testLearningPointsToTheFocusedNavigationOwner(): void
    {
        $learning = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-learning/SKILL.md');

        self::assertIsString($learning);
        self::assertStringContainsString('`agent-loop-investigate` for adaptive PHP navigation', $learning);
        self::assertStringNotContainsString('`agent-loop-discipline` for adaptive PHP navigation', $learning);
    }
}
