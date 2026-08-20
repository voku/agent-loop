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

    public function instruction(): string
    {
        return match ($this) {
            self::ASK => 'ask before optional model-generated human explanation artifacts when a human is interactively available; if the run is unattended or cannot ask, skip them and continue',
            self::ALWAYS => 'optional model-generated human explanation artifacts may be produced without asking when useful',
            self::NEVER => 'skip optional model-generated human explanation artifacts and continue without spending model work on them',
        };
    }
}
