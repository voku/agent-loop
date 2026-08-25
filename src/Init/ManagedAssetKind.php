<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * A class of repository-projected host asset.
 *
 * Hooks are deliberately their own kind: they are executable, they are opt-in,
 * and conflating them with portable instructions/skills/subagents is exactly
 * the mistake the setup boundary must not make.
 */
enum ManagedAssetKind: string
{
    case SKILLS = 'skills';
    case SUBAGENTS = 'subagents';
    case HOOKS = 'hooks';

    public function isOptionalExecutable(): bool
    {
        return $this === self::HOOKS;
    }
}
