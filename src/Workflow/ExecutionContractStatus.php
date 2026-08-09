<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

enum ExecutionContractStatus: string
{
    case READY = 'ready';
    case BLOCKED = 'blocked';
    case REJECTED = 'rejected';
}
