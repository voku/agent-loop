<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;

final readonly class WorkflowTaskId
{
    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidArgumentException('Invalid workflow task id. Use only letters, numbers, dots, underscores, and hyphens; path traversal is not allowed.');
        }
    }

    public static function isValid(string $value): bool
    {
        return $value !== ''
            && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $value) === 1
            && !str_contains($value, '..');
    }
}
