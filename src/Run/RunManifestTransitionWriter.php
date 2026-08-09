<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

/**
 * Refreshes the derived run projection after a workflow-owned state transition.
 *
 * The focused package artifacts have already changed when this is called. The
 * writer observes those authorities and atomically replaces the projection; it
 * never pushes manifest state back into them.
 */
final readonly class RunManifestTransitionWriter
{
    public function __construct(private string $rootPath)
    {
    }

    public function write(string $taskId): string
    {
        $manifest = (new GovernedRunManifestProjector($this->rootPath))->project($taskId);
        $path = (new RunManifestStore($this->rootPath))->write($manifest);

        return RelativePath::fromRoot($this->rootPath, $path);
    }
}
