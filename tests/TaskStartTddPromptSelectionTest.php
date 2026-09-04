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
            dirname(__DIR__) . '/resources/skills/agent-loop-task-start/SKILL.md',
        );

        self::assertMatchesRegularExpression(
            '/For behavior-changing.*?test-driven-development.*?--operating-prompt-manifest.*?--operating-prompt/s',
            $skill,
        );
        self::assertMatchesRegularExpression(
            '/For a specific bug claim.*?reproduce-before-fix.*?Do not stack\s+both/s',
            $skill,
        );
        self::assertStringContainsString('Recall owns the recipe semantics', $skill);
        self::assertStringContainsString('Do not restate RED/GREEN/REFACTOR', $skill);
    }
}
