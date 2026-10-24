<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRun;

/** Resolves the learning root used by a governed project. */
final class WorkflowLearningRoot
{
    /**
     * The durable Learning repository a governed Run is bound to.
     *
     * A Run records this at preparation time so its close evidence stays
     * explainable from the Run alone, without re-deriving the location from
     * ambient defaults or from a caller-supplied flag that nothing durable
     * remembers.
     */
    public static function forRun(string $rootPath, GovernedRun $run): string
    {
        $layout = new ProjectLayout($rootPath);

        return $run->learningRoot === null
            ? $layout->learningRoot()
            : $layout->projectOwnedPath($run->learningRoot, 'learning_root');
    }
}
