<?php

declare(strict_types=1);

namespace voku\AgentLoop;

enum HumanExplanationPolicy: string
{
    case ASK = 'ask';
    case ALWAYS = 'always';
    case NEVER = 'never';

    /** @return 'ask'|'generate'|'skip' */
    public function interactiveBehavior(): string
    {
        return match ($this) {
            self::ASK => 'ask',
            self::ALWAYS => 'generate',
            self::NEVER => 'skip',
        };
    }

    /** @return 'generate'|'skip' */
    public function unattendedBehavior(): string
    {
        return match ($this) {
            self::ALWAYS => 'generate',
            self::ASK, self::NEVER => 'skip',
        };
    }
}
