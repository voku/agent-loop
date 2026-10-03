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

    public function testWorkflowSkillKeepsPromptControlsRoutable(): void
    {
        $workflow = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-workflow/SKILL.md');

        self::assertIsString($workflow);
        self::assertStringContainsString('checkpoint-autonomy', $workflow);
        self::assertStringContainsString('momentum', $workflow);
        self::assertStringContainsString('Reflection is deliberately **not** another lifecycle phase.', $workflow);
    }
}
