<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use Throwable;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowContextCommand;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;

/**
 * Assembles the read-only task-transparency projection from existing owners.
 *
 * Every section here is produced by whichever component already owns that
 * truth. This service composes; it decides nothing, writes nothing, and repairs
 * nothing it finds stale or missing.
 */
final readonly class WorkflowTransparencyService
{
    public function __construct(private string $rootPath)
    {
    }

    public function task(string $taskId): TaskTransparencyProjection
    {
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        $scope = ApprovedScope::fromContract($contract);
        $observation = (new RepositoryObservationCollector($this->rootPath))->collect($contract);

        return new TaskTransparencyProjection(
            taskId: $taskId,
            contract: $contract === null ? ContractBoundary::missing() : ContractBoundary::fromContract($contract),
            observation: $observation,
            scopeCoverage: ScopeCoverage::fromObservation($observation, $scope),
            implementation: ImplementationIdentity::capture($this->rootPath, $contract),
            context: (new WorkflowContextCommand($this->rootPath))->coverage($taskId),
            review: (new WorkflowReviewReportReader($this->rootPath))->detail($taskId),
            blocked: BlockedRecord::find($this->rootPath, $taskId),
            deferredFollowUp: $this->deferredFollowUp($taskId),
        );
    }

    /**
     * A defer exists only where the durable Run Learning close-out recorded one.
     *
     * A missing Learning root, an unreadable decision or a Run that never closed
     * all mean "nothing recorded" — never "nothing deferred was intended".
     */
    private function deferredFollowUp(string $taskId): ?DeferredFollowUp
    {
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        if ($run === null) {
            return null;
        }

        $root = WorkflowLearningRoot::forRun($this->rootPath, $run);
        if (!is_dir($root)) {
            return null;
        }

        $decision = null;
        try {
            $decision = (new RunLearningDecisionStore($root))->find($run->runId);
        } catch (Throwable) {
            // An unreadable decision is "nothing recorded", never "nothing was
            // deferred": the projection reports no defer rather than inventing
            // one, and the Learning owner keeps the repair.
            $decision = null;
        }

        return $decision === null ? null : DeferredFollowUp::fromDecision($decision);
    }
}
