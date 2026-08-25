<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum RepositorySetupIntegrationState: string
{
    case READY = 'ready';
    case MISSING = 'missing';
    case CONFLICT = 'conflict';
    case MANUAL = 'manual';
    case UNSUPPORTED = 'unsupported';
    case NOT_DECLARED = 'not_declared';
}
