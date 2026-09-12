<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum SubagentMutationIntent: string
{
    case ReadOnly = 'read-only';
    case Writable = 'writable';
}
