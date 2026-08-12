<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRun;

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
        return PathResolver::join($rootPath, $run->learningRoot);
    }

    /**
     * Refuses an explicit override that disagrees with the Run's own binding,
     * so a governed command can never gate against a different Learning
     * repository than the one the Run declares.
     */
    public static function assertRunBinding(string $rootPath, GovernedRun $run, ?string $explicitRoot): string
    {
        $bound = self::forRun($rootPath, $run);
        if ($explicitRoot === null) {
            return $bound;
        }

        $requested = PathResolver::join($rootPath, rtrim($explicitRoot, '/'));
        if ($requested !== $bound) {
            throw new RuntimeException(sprintf(
                'Governed Run %s is bound to Learning root %s; --learning-root %s would gate against different durable evidence.',
                $run->runId,
                $run->learningRoot,
                PathResolver::relativeTo($rootPath, $requested),
            ));
        }

        return $bound;
    }
}
