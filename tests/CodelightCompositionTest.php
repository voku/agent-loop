<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class CodelightCompositionTest extends TestCase
{
    public function testBootstrapDoesNotCopyOrRoutePortableEngineeringReasoning(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');
        $assets = file_get_contents(dirname(__DIR__) . '/docs/reference/agent-assets.md');

        self::assertIsString($skill);
        self::assertIsString($assets);
        self::assertLessThanOrEqual(2_500, strlen($skill));

        foreach ([
            'engineering-codelight',
            'coding-simplicity',
            'php-static-analysis',
            'linux-strace',
            'php-best-practices',
            '## Engineering Skill Routing',
            '## Nine laws',
            '### 1. Evidence and authority',
            'no code -> reuse -> stdlib/native',
        ] as $specialistRule) {
            self::assertStringNotContainsString($specialistRule, $skill);
        }

        foreach ([
            'engineering-codelight',
            'coding-simplicity',
            'php-static-analysis',
            'linux-strace',
            'Select the smallest relevant combination.',
        ] as $discoverableSkill) {
            self::assertStringContainsString($discoverableSkill, $assets);
        }

        self::assertStringContainsString('next_action_kind', $skill);
        self::assertStringContainsString('next_action', $skill);
    }

    public function testBootstrapKeepsColonBearingDescriptionYamlSafe(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString(
            'description: "Always-on agent-loop workflow floor: persisted lifecycle authority, evidence integrity, and concise receipts."',
            $skill,
        );
    }
}
