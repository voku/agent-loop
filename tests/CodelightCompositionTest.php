<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class CodelightCompositionTest extends TestCase
{
    public function testBootstrapRoutesWithoutCopyingPortableReasoning(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertLessThanOrEqual(8_000, strlen($skill));
        self::assertStringContainsString('engineering-codelight', $skill);
        self::assertStringContainsString('coding-simplicity', $skill);
        self::assertStringContainsString('next_action_kind', $skill);
        self::assertStringContainsString('next_action', $skill);
        self::assertStringNotContainsString('## Nine laws', $skill);
        self::assertStringNotContainsString('### 1. Evidence and authority', $skill);
        self::assertStringNotContainsString('no code -> reuse -> stdlib/native', $skill);
    }
}
