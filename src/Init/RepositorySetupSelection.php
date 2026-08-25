<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum RepositorySetupSelection: string
{
    case EXPLICIT = 'explicit';
    case AUTO = 'auto';
    case AMBIGUOUS = 'ambiguous';
    case MISSING = 'missing';
}
