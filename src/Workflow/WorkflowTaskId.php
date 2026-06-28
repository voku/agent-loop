<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

final readonly class WorkflowTaskId
{
    public function __construct(public string $value)
    {
        if ($value === '' || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $value) || str_contains($value, '..')) {
            throw new InvalidArgumentException('Invalid workflow task id. Use only letters, numbers, dots, underscores, and hyphens; path traversal is not allowed.');
        }
    }
}
