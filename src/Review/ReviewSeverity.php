<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

enum ReviewSeverity: string
{
    case OK = 'OK';
    case INFO = 'INFO';
    case WARN = 'WARN';
    case FAIL = 'FAIL';
}
