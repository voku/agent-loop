<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class PremiseCheckGuidanceTest extends TestCase
{
    public function testCheckpointAndMomentumRemainExplicitPromptControls(): void
    {
        $manifest = file_get_contents(dirname(__DIR__) . '/resources/prompts/operating-prompts.json');
        $workflow = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-workflow/SKILL.md');

        self::assertIsString($manifest);
        self::assertIsString($workflow);
        self::assertStringContainsString('"id": "checkpoint-autonomy"', $manifest);
        self::assertStringContainsString('"id": "momentum"', $manifest);
        self::assertStringContainsString('checkpoint-autonomy', $workflow);
        self::assertStringContainsString('momentum', $workflow);
    }

    public function testPromptControlsPreserveAuthorityWithoutAlwaysOnPremisePolicy(): void
    {
        $manifest = file_get_contents(dirname(__DIR__) . '/resources/prompts/operating-prompts.json');
        $discipline = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($manifest);
        self::assertIsString($discipline);
        self::assertStringContainsString('do not stop merely because there is useful progress to report', $manifest);
        self::assertStringContainsString('must not override current project or workflow evidence', $manifest);
        self::assertStringNotContainsString('## Prompt Controls', $discipline);
        self::assertStringNotContainsString('HUMAN_DECISION_REQUIRED', $discipline);
        self::assertStringNotContainsString('A conceivable alternative alone is not evidence.', $discipline);
    }
}
