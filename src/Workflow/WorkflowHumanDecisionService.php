<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLearning\RunLearningDecision;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

/**
 * Typed human-authority boundary shared by non-CLI adapters.
 *
 * This service records explicit human decisions through the same owning stores
 * used by the workflow CLI. It does not advance deterministic lifecycle work or
 * infer which decision should happen next.
 */
final readonly class WorkflowHumanDecisionService
{
    public function __construct(private string $rootPath)
    {
    }

    public function approveContract(string $taskId, string $approvedBy): TaskContract
    {
        $task = new WorkflowTaskId($taskId);

        return (new TaskContractStore($this->rootPath))->approve($task->value, $approvedBy);
    }

    public function acknowledgeReview(
        string $taskId,
        string $reportSha256,
        string $acknowledgedBy,
    ): ReviewAcknowledgement {
        $task = new WorkflowTaskId($taskId);
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $reportSha256) !== 1) {
            throw new RuntimeException('Review acknowledgement requires a sha256:<64 lowercase hex> report identity.');
        }

        [$contract, $run, $session] = $this->currentRunContext($task->value);
        $boundary = PostExecutionEvidenceBoundary::inspect($this->rootPath, $contract, $session);
        $currentSha256 = $boundary->reviewEvidenceSha256();
        $review = (new WorkflowReviewReportReader($this->rootPath))->read($task->value);

        if (
            $currentSha256 === null
            || $review['invalid']
            || !in_array($review['report_status'], ['ok', 'warn'], true)
        ) {
            throw new RuntimeException('Review acknowledgement requires a current non-failing blind-spot report.');
        }
        if (!hash_equals($currentSha256, $reportSha256)) {
            throw new RuntimeException('Provided review report identity does not match the exact current blind-spot report.');
        }

        return (new ReviewAcknowledgementStore($this->rootPath))->record(
            $run,
            $contract,
            $boundary->implementation,
            $currentSha256,
            $acknowledgedBy,
        );
    }

    /** @param list<FinishFindingInput> $findingInputs */
    public function recordLearning(
        string $taskId,
        string $decision,
        string $decidedBy,
        string $reason,
        array $findingInputs = [],
        ?string $followUpRef = null,
    ): RunLearningDecision {
        $task = new WorkflowTaskId($taskId);
        [$contract, $run, $session] = $this->currentRunContext($task->value);

        return (new WorkflowLearningRecorder($this->rootPath))->record(
            $run,
            $contract,
            $session,
            $decision,
            $decidedBy,
            $reason,
            $findingInputs,
            $followUpRef,
        );
    }

    /** @return array{0: TaskContract, 1: GovernedRun, 2: Session} */
    private function currentRunContext(string $taskId): array
    {
        $contract = (new TaskContractStore($this->rootPath))->load($taskId);
        $run = (new GovernedRunStore($this->rootPath))->find($taskId)
            ?? throw new RuntimeException('Human decision requires a governed Run.');
        $session = $this->sessionForRun($run)
            ?? throw new RuntimeException('Human decision requires the active Session bound to the governed Run.');

        if ($contract->status !== TaskContract::APPROVED || $contract->revision !== $run->contractRevision) {
            throw new RuntimeException('Human decision requires the governed Run to match the current approved Contract.');
        }

        return [$contract, $run, $session];
    }

    private function sessionForRun(GovernedRun $run): ?Session
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return null;
        }

        $store = new SessionStore();
        if (!$store->exists($root, $run->sessionId)) {
            return null;
        }

        $session = $store->load($root, $run->sessionId);
        if ($session->taskId !== $run->taskId) {
            throw new RuntimeException('Governed Run Session belongs to another task.');
        }

        return $session;
    }
}
