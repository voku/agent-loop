<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

enum ExecutionProfileName: string
{
    case MANUAL = 'manual';
    case SURGICAL = 'surgical';
    case STANDARD = 'standard';
    case HARDENED = 'hardened';
}
