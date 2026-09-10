<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class AdaptiveNavigationGuidanceTest extends TestCase
{
    public function testDisciplineUsesAdaptivePhpNavigation(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertLessThanOrEqual(8_000, strlen($skill));
        self::assertStringContainsString('Use the cheapest reliable navigation', $skill);
        self::assertStringContainsString('unknown implementation ownership', $skill);
        self::assertStringContainsString('relevant fresh Map already exists', $skill);
        self::assertStringContainsString('do not build Map merely to satisfy policy', $skill);
        self::assertStringContainsString('fall back to CLI navigation', $skill);
        self::assertStringContainsString('Do not mechanically repeat equivalent discovery', $skill);
        self::assertStringNotContainsString('Use Map first for PHP navigation', $skill);
    }

    /**
     * A named PHP symbol is Map's question, not ripgrep's.
     *
     * #344 replaced Map-first ceremony with adaptive navigation and, in the same
     * sentence, put "known files/symbols" on the `rg` side. That made this skill
     * contradict `agent-loop-investigate`, which says not to rediscover by text an
     * identity `scope` already resolves. Two skills in one package then answered the
     * same question differently, and the cheaper-looking answer was the wrong one.
     */
    public function testANamedPhpIdentityIsNotRoutedToTextSearch(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringNotContainsString('known files/symbols', $skill);
        self::assertStringContainsString('identity-shaped', $skill);
        self::assertStringContainsString('rather than rediscovering that identity by text', $skill);
    }

    /** Text-shaped questions keep their cheap answer; this is not a ban on `rg`. */
    public function testTextShapedQuestionsStillPreferNativeSearch(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString('text-shaped', $skill);
        self::assertStringContainsString('prefer `rg`, `rg --files`, and focused source reads', $skill);
    }

    /** The two skills that both route PHP navigation must not disagree. */
    public function testDisciplineAndInvestigateAgreeOnIdentityRouting(): void
    {
        $discipline = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');
        $investigate = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-investigate/SKILL.md');

        self::assertIsString($discipline);
        self::assertIsString($investigate);
        self::assertStringContainsString(
            'Do not use text search to rediscover a PHP identity that `scope` already resolves.',
            $investigate,
        );
        self::assertStringNotContainsString('known files/symbols', $discipline);
    }

    /** A cheap question must not be turned into a cold Map build. */
    public function testStaleMapDoesNotForceARebuildForASmallQuestion(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString('repairing it costs more than the question warrants', $skill);
        self::assertStringContainsString('do not build Map merely to satisfy policy', $skill);
    }

    /**
     * The route has to name the commands, not merely the shape.
     *
     * "identity-shaped" tells a host which category it is in; it does not tell it
     * what to run. A routing rule that cannot be executed without a second lookup
     * is the same defect as no routing rule, so the exact resolution command and
     * the exact planned-edit command are both pinned here.
     */
    public function testTheIdentityRouteNamesTheExactCommandsToRun(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString('`map scope`', $skill, 'Identity resolution must name map scope.');
        self::assertStringContainsString('`map context`', $skill, 'A planned edit must name map context.');
        self::assertMatchesRegularExpression(
            '/`map scope`.{0,60}`map context` for a planned edit/s',
            $skill,
            'scope resolves the identity; context is the planned-edit path. Both, in that order.',
        );
    }

    /** Supported structural mutation routes to a governed plan. */
    public function testStructuralMutationRoutesToAGovernedPlan(): void
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);
        self::assertStringContainsString('governed Map plan for supported rename/removal/move', $skill);
    }

    public function testPackageDocumentationKeepsCliAndMapComplementary(): void
    {
        $info = file_get_contents(dirname(__DIR__) . '/docs/reference/agent-assets.md');

        self::assertIsString($info);
        self::assertStringContainsString('adaptive PHP navigation across CLI and agent-map', $info);
        self::assertStringContainsString('Choose navigation by the information needed', $info);
        self::assertStringContainsString('without building Map merely for policy compliance', $info);
        self::assertStringContainsString('Use Map for structural PHP questions', $info);
        self::assertStringContainsString('fall back to CLI navigation', $info);
        self::assertStringContainsString('Do not mechanically repeat equivalent discovery', $info);
    }

    public function testLearningPointsBackToAdaptivePolicyOwner(): void
    {
        $learning = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-learning/SKILL.md');

        self::assertIsString($learning);
        self::assertStringContainsString('`agent-loop-discipline` for adaptive PHP navigation', $learning);
        self::assertStringNotContainsString('`agent-loop-discipline` for map-first navigation', $learning);
    }
}
