<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class CodelightCompositionTest extends TestCase
{
    public function testBootstrapDefersEngineeringRoutingToOwnedSurfaces(): void
    {
        $root = dirname(__DIR__);
        $discipline = file_get_contents($root . '/resources/skills/agent-loop-discipline/SKILL.md');
        $router = file_get_contents($root . '/AGENTS.md');
        $assets = file_get_contents($root . '/docs/reference/agent-assets.md');

        self::assertIsString($discipline);
        self::assertIsString($router);
        self::assertIsString($assets);
        self::assertLessThanOrEqual(8_000, strlen($discipline));

        self::assertStringNotContainsString('## Engineering Skill Routing', $discipline);
        self::assertStringNotContainsString('coding-simplicity', $discipline);
        self::assertStringNotContainsString('php-static-analysis', $discipline);
        self::assertStringNotContainsString('linux-strace', $discipline);
        self::assertStringNotContainsString('php-best-practices', $discipline);
        self::assertStringNotContainsString('## Nine laws', $discipline);
        self::assertStringNotContainsString('### 1. Evidence and authority', $discipline);
        self::assertStringNotContainsString('no code -> reuse -> stdlib/native', $discipline);

        self::assertStringContainsString('voku/agent-skills/engineering-codelight', $router);
        self::assertStringContainsString('engineering-codelight', $assets);
        self::assertStringContainsString('coding-simplicity', $assets);
        self::assertStringContainsString('php-static-analysis', $assets);
        self::assertStringContainsString('linux-strace', $assets);
    }

    public function testBootstrapKeepsColonBearingDescriptionYamlSafe(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString(
            'description: "Minimal governed bootstrap: lifecycle authority, evidence integrity, and bounded workflow output."',
            $skill,
        );
    }
}
