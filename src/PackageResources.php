<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use InvalidArgumentException;

/**
 * The single owner of package-shipped resource locations.
 *
 * Setup, hook, prompt and review code all need to find assets this package
 * ships. While each caller spelled the physical directory itself, moving one
 * asset meant editing production code, dogfood runners, CI and tests in lockstep
 * and getting a half-move whenever one of them was missed. Callers ask here
 * instead, so the layout is a fact of this class rather than of every consumer.
 *
 * The relative constants are public because they are also the default source
 * roots a consuming repository uses for its own assets, and because tests assert
 * the shipped layout against them.
 */
final class PackageResources
{
    public const string SKILLS = 'resources/skills';

    public const string SUBAGENTS = 'resources/subagents';

    public const string TOOLS = 'resources/tools';

    public const string GIT_HOOKS = 'resources/githooks';

    public const string MAKE_INCLUDE = 'resources/make/agent-loop.mk';

    private const string PROJECT_INSTRUCTIONS = 'resources/instructions/project-instructions.md';

    private const string OPERATING_PROMPTS = 'resources/prompts/operating-prompts.json';

    private const string REVIEW = 'resources/review';

    /** Host hook bundles this package ships, keyed by canonical agent name. */
    private const array HOOK_AGENTS = ['codex', 'claude'];

    public static function subagentsRoot(): string
    {
        return self::path(self::SUBAGENTS);
    }

    public static function gitHooksRoot(): string
    {
        return self::path(self::GIT_HOOKS);
    }

    public static function projectInstructions(): string
    {
        return self::path(self::PROJECT_INSTRUCTIONS);
    }

    public static function operatingPrompts(): string
    {
        return self::path(self::OPERATING_PROMPTS);
    }

    public static function reviewRoot(): string
    {
        return self::path(self::REVIEW);
    }

    /**
     * Relative location of a shipped host hook bundle.
     *
     * @throws InvalidArgumentException when the package ships no bundle for the agent
     */
    public static function hooks(string $agent): string
    {
        if (!in_array($agent, self::HOOK_AGENTS, true)) {
            throw new InvalidArgumentException('No package hook bundle is shipped for agent: ' . $agent);
        }

        return 'resources/hooks/' . $agent;
    }

    /**
     * @throws InvalidArgumentException when the package ships no bundle for the agent
     */
    public static function hooksRoot(string $agent): string
    {
        return self::path(self::hooks($agent));
    }

    private static function path(string $relative): string
    {
        return dirname(__DIR__) . '/' . $relative;
    }
}
