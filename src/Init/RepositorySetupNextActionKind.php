<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum RepositorySetupNextActionKind: string
{
    case COMMAND = 'command';
    case HOST_WORK = 'host_work';
    case DECISION_REQUIRED = 'decision_required';
    case NONE = 'none';
}
