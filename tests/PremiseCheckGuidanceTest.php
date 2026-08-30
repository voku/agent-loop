<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class PremiseCheckGuidanceTest extends TestCase
{
    public function testCheckpointContinuesWithoutPremiseChurnWhenEvidenceStillFits(): void
    {
        $skill = $this->disciplineSkill();

        self::assertStringContainsString('If no evidence challenges the framing and no human gate exists, checkpoint and continue.', $skill);
        self::assertStringContainsString('A conceivable alternative alone is not evidence.', $skill);
    }

    public function testConcreteComplexityCanTriggerBoundedPremiseCheck(): void
    {
        $skill = $this->disciplineSkill();

        self::assertStringContainsString('On concrete avoidable complexity, repeated repair, or contradictory observations, check the premise before adding machinery', $skill);
        self::assertStringContainsString('approved outcome; assumption causing complexity; whether evidence still supports it; simpler route preserving Goal, acceptance, scope, and authority', $skill);
        self::assertStringContainsString('Trigger by evidence, never timer/count or every checkpoint.', $skill);
        self::assertStringNotContainsString('run premise check every checkpoint', strtolower($skill));
    }

    public function testPremiseResultsPreserveAgentAndHumanAuthority(): void
    {
        $skill = $this->disciplineSkill();

        self::assertStringContainsString('Result: `CONTINUE`, `REPLAN`, or `HUMAN_DECISION_REQUIRED`.', $skill);
        self::assertStringContainsString('`CONTINUE` needs materially new evidence before reopening.', $skill);
        self::assertStringContainsString('`REPLAN` is agent-owned when approved intent is unchanged; delete obsolete machinery pre-1.0.', $skill);
        self::assertStringContainsString('`HUMAN_DECISION_REQUIRED` is only for changing product intent, Goal, acceptance, scope, non-goals, public contract, or risk/irreversible authority.', $skill);
        self::assertStringNotContainsString('retain both approaches', strtolower($skill));
    }

    private function disciplineSkill(): string
    {
        $skill = file_get_contents(dirname(__DIR__) . '/docs/agents/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertLessThanOrEqual(8_000, strlen($skill));

        return $skill;
    }
}
