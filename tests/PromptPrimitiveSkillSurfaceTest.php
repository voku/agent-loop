<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;

/** @internal */
final class PromptPrimitiveSkillSurfaceTest extends TestCase
{
    public function testBundledDisciplineActuallyInjectsPromptControlsAndReflectionBoundary(): void
    {
        $output = (new AgentDisciplineHook(dirname(__DIR__)))->contextOutput(
            'SessionStart',
            json_encode(['hook_event_name' => 'SessionStart'], JSON_THROW_ON_ERROR),
        );
        $context = $output['hookSpecificOutput']['additionalContext'];

        self::assertStringContainsString('checkpoint-autonomy', $context);
        self::assertStringContainsString('momentum', $context);
        self::assertStringContainsString('workflow reflect <task-id> --scope task', $context);
        self::assertStringContainsString('workflow reflect <task-id> --scope project', $context);
        self::assertStringContainsString('Never persist a synthetic human/self approval.', $context);
    }

    public function testWorkflowAndReviewCloseSkillsKeepReflectionOptionalAndRoutable(): void
    {
        $root = dirname(__DIR__) . '/docs/agents/skills';
        $workflow = (string) file_get_contents($root . '/agent-loop-workflow/SKILL.md');
        $reviewClose = (string) file_get_contents($root . '/agent-loop-review-close/SKILL.md');

        self::assertStringContainsString('checkpoint-autonomy', $workflow);
        self::assertStringContainsString('momentum', $workflow);
        self::assertStringContainsString('RETURN_TO_REVIEW', $workflow);
        self::assertStringContainsString('Reflection is deliberately **not** another lifecycle phase.', $workflow);

        self::assertStringContainsString('RETURN_TO_REVIEW', $reviewClose);
        self::assertStringContainsString('Reflection is not one', $reviewClose);
        self::assertStringContainsString('workflow reflect <task-id> --scope project', $reviewClose);
        self::assertStringContainsString('workflow reflect <task-id> --scope task', $reviewClose);
    }
}
