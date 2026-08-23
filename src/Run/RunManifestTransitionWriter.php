<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

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
        $this->prepareExecutionPlan($taskId);

        return $this->writeProjection($taskId);
    }

    /**
     * Write only the derived projection after final close evidence is already durable.
     *
     * Close must remain resumable even if an optional execution binding has drifted:
     * verification receipt and Session status are authority, while the manifest is a
     * derived projection. Normal workflow transitions continue to use write().
     */
    public function writeRecoveryProjection(string $taskId): string
    {
        return $this->writeProjection($taskId);
    }

    private function writeProjection(string $taskId): string
    {
        $manifest = (new RunManifestProjector($this->rootPath))->project($taskId);
        $path = (new RunManifestStore($this->rootPath))->write($manifest);

        return PathResolver::relativeTo($this->rootPath, $path);
    }

    /**
     * Resolve the selected execution topology exactly once after a current
     * approved Contract has a governed Run. Subsequent workflow transitions
     * re-read the immutable plan and fail closed if its binding drifted.
     */
    private function prepareExecutionPlan(string $taskId): void
    {
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        if (!$contract instanceof TaskContract || $contract->status !== TaskContract::APPROVED) {
            return;
        }

        $run = (new GovernedRunStore($this->rootPath))->findForContract($contract);
        if ($run === null) {
            return;
        }

        $plan = (new ExecutionPlanStore($this->rootPath))->prepare($run, $contract);
        (new ExecutionStateStore($this->rootPath))->prepare($plan);
    }
}
