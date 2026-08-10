<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use voku\AgentLoop\ProjectLayout;

/** Resolves the learning root used by a governed project. */
final class WorkflowLearningRoot
{
    public static function resolve(string $rootPath, ?string $explicitRoot): string
    {
        if ($explicitRoot !== null) {
            return $explicitRoot;
        }

        $resolved = (new ProjectLayout($rootPath))->learningRoot();
        if ($resolved !== null) {
            return $resolved;
        }

        throw new InvalidArgumentException('no agent-learning root found; run "agent-loop init scaffold" or pass --learning-root <path>.');
    }
}
