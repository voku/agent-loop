<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class PremiseCheckGuidanceTest extends TestCase
{
    public function testAlwaysOnDisciplineDoesNotOwnConditionalPromptControls(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringNotContainsString('## Prompt Controls', $skill);
        self::assertStringNotContainsString('checkpoint-autonomy', $skill);
        self::assertStringNotContainsString('momentum', $skill);
        self::assertStringNotContainsString('A conceivable alternative alone is not evidence.', $skill);
    }

    public function testOperatingPromptManifestOwnsSelectedL1Controls(): void
    {
        $manifest = file_get_contents(dirname(__DIR__) . '/resources/prompts/operating-prompts.json');

        self::assertIsString($manifest);
        self::assertStringContainsString('"id": "checkpoint-autonomy"', $manifest);
        self::assertStringContainsString('"id": "momentum"', $manifest);
        self::assertStringContainsString('Stop for human input only when approval', $manifest);
        self::assertStringContainsString('Re-check anything whose authority, freshness, repository scope, or assumptions may have changed.', $manifest);
    }

    public function testExplicitlyRequestedLocalCardsUseOwnerGeneratedIdentityRegardlessOfFutureWorkMode(): void
    {
        $workflow = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-workflow/SKILL.md');

        self::assertIsString($workflow);
        $frontDoor = explode('## Ambiguous New Work Items', explode('## End-to-End Implementation Front Door', $workflow, 2)[1] ?? '', 2)[0];

        self::assertStringContainsString('board card create --generate-id --title', $frontDoor);
        self::assertStringContainsString('never calculate `highest number + 1`', $frontDoor);
        self::assertStringContainsString('external ticket authority', $frontDoor);
        self::assertStringContainsString('result\'s `card` ID unchanged', $frontDoor);
    }

    public function testTaskStartRoutesNewLocalCardIdsToOwnerInsteadOfSequentialExamples(): void
    {
        $taskStart = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-task-start/SKILL.md');

        self::assertIsString($taskStart);
        self::assertStringContainsString('follow `agent-loop-workflow` to obtain the Kanban owner', $taskStart);
        self::assertStringContainsString('Never derive an ID by incrementing a branch-local maximum.', $taskStart);
        self::assertStringNotContainsString('LOCAL-001', $taskStart);
    }

    public function testWorkflowSkillKeepsPromptControlsRoutable(): void
    {
        $workflow = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-workflow/SKILL.md');

        self::assertIsString($workflow);
        self::assertStringContainsString('checkpoint-autonomy', $workflow);
        self::assertStringContainsString('momentum', $workflow);
        self::assertStringContainsString('Reflection is deliberately **not** another lifecycle phase.', $workflow);
    }
}
