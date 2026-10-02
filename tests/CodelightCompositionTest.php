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
        self::assertStringContainsString('php-static-analysis', $skill);
        self::assertStringContainsString('linux-strace', $skill);
        self::assertStringNotContainsString('php-best-practices', $skill);
        self::assertStringContainsString('Route non-trivial reasoning to `engineering-codelight`', $skill);
        self::assertStringContainsString('minimization to `coding-simplicity`', $skill);
        self::assertStringContainsString('PHP static-analysis/type-contract work to `php-static-analysis`', $skill);
        self::assertStringContainsString('Linux syscall/runtime bottleneck diagnosis to `linux-strace`', $skill);
        self::assertStringContainsString(
            'Generic PHP and framework knowledge stays with the model, repository evidence, and current owner documentation instead of a cookbook skill.',
            $skill,
        );
        self::assertStringContainsString('next_action_kind', $skill);
        self::assertStringContainsString('next_action', $skill);
        self::assertStringNotContainsString('## Nine laws', $skill);
        self::assertStringNotContainsString('### 1. Evidence and authority', $skill);
        self::assertStringNotContainsString('no code -> reuse -> stdlib/native', $skill);
    }

    public function testBootstrapKeepsColonBearingDescriptionYamlSafe(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString(
            'description: "Governed agent-* orchestration: resumable state, adaptive navigation, evidence, L2 gates, review routing."',
            $skill,
        );
    }
}
