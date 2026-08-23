<?php

declare(strict_types=1);

namespace voku\AgentLoop;

enum FutureWorkMode: string
{
    case FOCUS = 'focus';
    case DISCOVER = 'discover';
    case INVEST = 'invest';
}
