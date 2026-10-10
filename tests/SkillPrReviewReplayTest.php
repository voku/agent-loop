<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowLearningRecorder;

/** Regression cases from actual consumer skill review: voku/agent-ui#104. */
final class SkillPrReviewReplayTest extends TestCase
{
    public function testInvestigateBadGrepExampleDoesNotMistakeThePatternForAnOption(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-investigate/SKILL.md');
        self::assertIsString($skill);

        // Even a "Bad" navigation example should fail for its actual lesson,
        // not because a pattern starting with a hyphen is parsed as an option.
        self::assertStringContainsString('grep -rn -e "->save(" src/', $skill);
        self::assertStringNotContainsString('grep -rn "->save(" src/', $skill);
    }

    public function testLearningReviewerSuggestionDoesNotOverrideRealDecisionContract(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-learning-boundary/SKILL.md');
        self::assertIsString($skill);

        // The other review comment on agent-ui#104 was a false positive:
        // --finding and --follow-up imply the status, and --reason is optional.
        self::assertSame('findings_recorded', WorkflowLearningRecorder::resolveDecision(null, true, null));
        self::assertSame('follow_up_required', WorkflowLearningRecorder::resolveDecision(null, false, 'issue://voku/agent-loop/104'));
        self::assertStringContainsString('`--finding` and `--follow-up` already state the decision.', $skill);
        self::assertStringContainsString('`--reason` is optional context', $skill);
    }
}
