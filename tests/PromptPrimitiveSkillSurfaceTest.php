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

    public function testWorkflowReviewAndReflectionSkillsKeepPromptPrimitivesRoutable(): void
    {
        $root = dirname(__DIR__) . '/docs/agents/skills';
        $workflow = (string) file_get_contents($root . '/agent-loop-workflow/SKILL.md');
        $reviewClose = (string) file_get_contents($root . '/agent-loop-review-close/SKILL.md');
        $codeReview = (string) file_get_contents($root . '/agent-loop-code-review/SKILL.md');
        $promptPrimitives = (string) file_get_contents(dirname(__DIR__) . '/docs/agents/PROMPT_PRIMITIVES.md');

        self::assertStringContainsString('checkpoint-autonomy', $workflow);
        self::assertStringContainsString('momentum', $workflow);
        self::assertStringContainsString('RETURN_TO_REVIEW', $workflow);
        self::assertStringContainsString('Reflection is deliberately **not** another lifecycle phase.', $workflow);

        self::assertStringContainsString('RETURN_TO_REVIEW', $reviewClose);
        self::assertStringContainsString('Reflection is not one', $reviewClose);
        self::assertStringContainsString('workflow reflect <task-id> --scope project', $reviewClose);
        self::assertStringContainsString('workflow reflect <task-id> --scope task', $reviewClose);

        self::assertStringContainsString('agent-loop review code <task-id>', $codeReview);
        self::assertStringContainsString('agent-loop review first-draft', $codeReview);
        self::assertStringContainsString('STATUS: clean', $codeReview);
        self::assertStringContainsString('no evidence-backed defect', $codeReview);

        self::assertStringContainsString('adversarial-review', $promptPrimitives);
        self::assertStringContainsString('minimum_failure_modes', $promptPrimitives);
        self::assertStringContainsString('not** a quota of defects', $promptPrimitives);
        self::assertStringContainsString('review first-draft', $promptPrimitives);
        self::assertStringContainsString('review code TASK-1', $promptPrimitives);
        self::assertStringContainsString('agent-loop prompt guidance-gaps', $promptPrimitives);
        self::assertStringContainsString('not** a default workflow stage', $promptPrimitives);
        self::assertStringContainsString('implementation-notes.html', $promptPrimitives);
        self::assertStringContainsString('HUMAN_DECISION_REQUIRED', $promptPrimitives);
        self::assertStringContainsString('does not automatically edit docs or skills', $promptPrimitives);
        self::assertStringContainsString('agent-loop prompt future-work --scope project', $promptPrimitives);
        self::assertStringContainsString('Inside a governed Run, prefer `workflow reflect`', $promptPrimitives);
    }
}
