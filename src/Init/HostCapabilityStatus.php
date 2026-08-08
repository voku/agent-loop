<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

enum HostCapabilityStatus: string
{
    case Supported = 'supported';
    case Degraded = 'degraded';
    case Unsupported = 'unsupported';
}
