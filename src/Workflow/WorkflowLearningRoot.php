<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\ProjectLayout;

/** Resolves the learning root used by a governed project. */
final class WorkflowLearningRoot
{
    public static function resolve(string $rootPath, ?string $explicitRoot): string
    {
        if ($explicitRoot !== null) {
            return $explicitRoot;
        }

        return (new ProjectLayout($rootPath))->learningRoot();
    }
}
