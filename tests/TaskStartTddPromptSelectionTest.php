<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class TaskStartTddPromptSelectionTest extends TestCase
{
    public function testBehaviorChangingWorkSelectsTheRecallOwnedTddRecipeWithoutDuplicatingItsSemantics(): void
    {
        $skill = (string) file_get_contents(
            dirname(__DIR__) . '/docs/agents/skills/agent-loop-task-start/SKILL.md',
        );

        self::assertStringContainsString('test-driven-development', $skill);
        self::assertStringContainsString('behavior-changing', $skill);
        self::assertStringContainsString('reproduce-before-fix', $skill);
        self::assertStringContainsString('Recall owns the recipe semantics', $skill);
        self::assertStringContainsString('Do not restate RED/GREEN/REFACTOR', $skill);
    }
}
