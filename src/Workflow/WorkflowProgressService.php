<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\Run\RunManifestProjector;

/** Typed read-only progress boundary for non-CLI presentation consumers. */
final readonly class WorkflowProgressService
{
    public function __construct(private string $rootPath)
    {
    }

    public function forTask(string $taskId): WorkflowProgress
    {
        $task = new WorkflowTaskId($taskId);
        $manifest = (new RunManifestProjector($this->rootPath))->project($task->value);

        return (new WorkflowProgressProjector())->project($manifest);
    }
}
