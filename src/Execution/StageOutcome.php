<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

enum StageOutcome: string
{
    case COMPLETED = 'completed';
    case BLOCKED = 'blocked';
    case NEEDS_CLARIFICATION = 'needs_clarification';
    case FAILED = 'failed';
    case PASS = 'pass';
    case CHANGES_REQUIRED = 'changes_required';
}
