<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum RepositorySetupRuntimeState: string
{
    case AVAILABLE = 'available';
    case MISSING = 'missing';
    case UNPROBED = 'unprobed';
}
