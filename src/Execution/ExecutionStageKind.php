<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

enum ExecutionStageKind: string
{
    case AGENT = 'agent';
    case DETERMINISTIC = 'deterministic';
}
