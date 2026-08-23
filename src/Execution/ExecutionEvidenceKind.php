<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

enum ExecutionEvidenceKind: string
{
    case ARTIFACT = 'artifact';
    case VALIDATION = 'validation';
}
