<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

enum ImplementationIdentityStatus: string
{
    case CAPTURED = 'captured';
    case UNAVAILABLE = 'unavailable';
    case REFUSED = 'refused';
    case NO_CONTRACT = 'no_contract';
}
