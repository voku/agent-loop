<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

enum ExecutionContextPolicy: string
{
    case REUSE_ALLOWED = 'reuse_allowed';
    case FRESH_REQUIRED = 'fresh_required';
}
