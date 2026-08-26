<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * A class of repository-projected host asset.
 *
 * Hooks are deliberately their own kind: they are executable, they are opt-in,
 * and conflating them with portable instructions/skills/subagents is exactly
 * the mistake the setup boundary must not make.
 *
 * Instructions are marker-owned repository files rather than manifest-owned
 * host directories. They still participate in typed install plans so a host can
 * preview every repository-owned write before applying it.
 */
enum ManagedAssetKind: string
{
    case INSTRUCTIONS = 'instructions';
    case SKILLS = 'skills';
    case SUBAGENTS = 'subagents';
    case HOOKS = 'hooks';

    public function isOptionalExecutable(): bool
    {
        return $this === self::HOOKS;
    }
}
