<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\SubagentDefinition;

/** @internal */
final class FirstPartyAgentAssetContractTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, list<string>}>
     */
    public static function roleProvider(): iterable
    {
        yield 'investigator' => [
            'agent-loop-investigate',
            'agent-loop-investigator',
            [
                'agent-loop map',
                'map discover --region',
                'map history coupling',
                'map history claims',
                'heuristic',
                'clean Git checkpoint',
                'real source',
                'Read-only',
            ],
        ];
        yield 'surgical builder' => [
            'agent-loop-surgical-edit',
            'agent-loop-surgical-builder',
            ['runner=auto', 'smallest', 'scope'],
        ];
        yield 'code reviewer' => [
            'agent-loop-code-review',
            'agent-loop-code-reviewer',
            ['raw diff', 'agent-loop map', 'correctness'],
        ];
    }

    /** @param list<string> $invariants */
    #[DataProvider('roleProvider')]
    public function testPortableSkillAndSubagentKeepRoleInvariants(
        string $skillName,
        string $subagentName,
        array $invariants,
    ): void {
        $root = dirname(__DIR__);
        $skillPath = $root . '/resources/skills/' . $skillName . '/SKILL.md';
        $subagentPath = $root . '/resources/subagents/' . $subagentName . '.md';

        self::assertFileExists($skillPath);
        self::assertFileExists($subagentPath);
        self::assertSame([], SubagentDefinition::validationErrors($subagentPath));

        $skill = (string) file_get_contents($skillPath);
        $subagent = (string) file_get_contents($subagentPath);
        foreach ($invariants as $invariant) {
            self::assertStringContainsStringIgnoringCase($invariant, $skill, $skillName . ' misses role invariant: ' . $invariant);
            self::assertStringContainsStringIgnoringCase($invariant, $subagent, $subagentName . ' misses role invariant: ' . $invariant);
        }

        if ($skillName === 'agent-loop-investigate') {
            self::assertStringContainsStringIgnoringCase('do not run `history observe` during investigation', $skill);
            self::assertStringContainsStringIgnoringCase('do not run `map history observe` during investigation', $subagent);
        }
    }

    public function testPackageOwnedAgentAssetsContainNoRemoteBootstrap(): void
    {
        $root = dirname(__DIR__) . '/resources';
        $paths = array_merge(
            glob($root . '/skills/*/SKILL.md') ?: [],
            glob($root . '/subagents/*.md') ?: [],
            glob($root . '/codex-hooks/*') ?: [],
            glob($root . '/codex-hooks/hooks/*.php') ?: [],
            glob($root . '/claude-hooks/*') ?: [],
            glob($root . '/claude-hooks/hooks/*.php') ?: [],
        );

        self::assertNotSame([], $paths);
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            $content = (string) file_get_contents($path);
            self::assertStringNotContainsString('raw.githubusercontent.com', $content, $path . ' contains a remote bootstrap URL.');
        }
    }
}
