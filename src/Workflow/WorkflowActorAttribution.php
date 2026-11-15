<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;

/** Validates actor labels before a workflow artifact persists them as attribution. */
final class WorkflowActorAttribution
{
    public static function normalize(string $actor, string $action): string
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new RuntimeException('A non-empty actor is required to ' . $action . '.');
        }
        if (str_starts_with($actor, 'standing:')) {
            throw new RuntimeException('The standing: actor prefix is reserved and cannot ' . $action . '.');
        }

        return $actor;
    }
}
