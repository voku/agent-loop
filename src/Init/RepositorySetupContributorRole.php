<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum RepositorySetupContributorRole: string
{
    case CONSUMER = 'consumer';
    case MAINTAINER = 'maintainer';
    case PROJECT = 'project';
}
