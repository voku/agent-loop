<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class AdaptiveNavigationGuidanceTest extends TestCase
{
    public function testInvestigateSkillOwnsStructuralPhpNavigation(): void
    {
        $skill = $this->investigateSkill();

        self::assertStringContainsString('Named class/method/function', $skill);
        self::assertStringContainsString("`vendor/bin/agent-loop map scope '<symbol>' --format=toon`", $skill);
        self::assertStringContainsString("`vendor/bin/agent-loop map context '<symbol>' --format=toon`", $skill);
        self::assertStringContainsString('Do not use text search to rediscover a PHP identity that `scope` already resolves.', $skill);
    }

    public function testTextShapedQuestionsStayWithNativeSearchInTheNavigationOwner(): void
    {
        $skill = $this->investigateSkill();

        self::assertStringContainsString('Literal/config/template', $skill);
        self::assertStringContainsString("`rg '<pattern>'` or `rg --files`", $skill);
    }

    public function testAlwaysOnDisciplineDoesNotCompeteWithNavigationOwner(): void
    {
        $discipline = $this->disciplineSkill();

        foreach ([
            '## Navigate Before Editing',
            'identity-shaped',
            'text-shaped',
            '`map scope`',
            '`map context`',
            'agent-loop map query',
        ] as $navigationRule) {
            self::assertStringNotContainsString($navigationRule, $discipline);
        }
    }

    public function testPackageDocumentationKeepsColdMapBuildOptional(): void
    {
        $info = $this->agentAssets();

        self::assertStringContainsString('without building Map merely for policy compliance', $info);
        self::assertStringContainsString('fall back to CLI navigation', $info);
        self::assertStringContainsString('Do not mechanically repeat equivalent discovery', $info);
    }

    public function testPackageDocumentationKeepsGovernedStructuralMutationRoute(): void
    {
        $info = $this->agentAssets();

        self::assertStringContainsString('prefer a governed Map plan for a supported rename, removal or move', $info);
    }

    public function testPackageDocumentationKeepsCliAndMapComplementary(): void
    {
        $info = $this->agentAssets();

        self::assertStringContainsString('Choose navigation by the information needed', $info);
        self::assertStringContainsString('Use Map for structural PHP questions', $info);
        self::assertStringContainsString('fall back to CLI navigation', $info);
        self::assertStringContainsString('Do not mechanically repeat equivalent discovery', $info);
    }

    public function testLearningPointsToTheTargetedNavigationOwner(): void
    {
        $learning = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-learning/SKILL.md');

        self::assertIsString($learning);
        self::assertStringContainsString('`agent-loop-investigate` for structural PHP navigation', $learning);
        self::assertStringNotContainsString('`agent-loop-discipline` for adaptive PHP navigation', $learning);
    }

    private function investigateSkill(): string
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-investigate/SKILL.md');

        self::assertIsString($skill);

        return $skill;
    }

    private function disciplineSkill(): string
    {
        $skill = file_get_contents(dirname(__DIR__) . '/resources/skills/agent-loop-discipline/SKILL.md');

        self::assertIsString($skill);

        return $skill;
    }

    private function agentAssets(): string
    {
        $info = file_get_contents(dirname(__DIR__) . '/docs/reference/agent-assets.md');

        self::assertIsString($info);

        return $info;
    }
}
