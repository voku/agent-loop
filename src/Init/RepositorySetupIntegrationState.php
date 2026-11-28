<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum RepositorySetupIntegrationState: string
{
    case READY = 'ready';
    case MISSING = 'missing';
    case CONFLICT = 'conflict';
    case MANUAL = 'manual';
    /** The project recorded that it does not adopt this projection (attribution, not authentication). */
    case WAIVED = 'waived';
    case UNSUPPORTED = 'unsupported';
    case NOT_DECLARED = 'not_declared';
}
