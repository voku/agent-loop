<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLearning\LearningRepositoryValidator;
use voku\AgentLearning\RunLearningDecision;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentSession\Session;

final readonly class WorkflowLearningRecorder
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $findingIds */
    public function record(
        GovernedRun $run,
        TaskContract $contract,
        Session $session,
        string $decisionValue,
        string $decidedBy,
        string $reason,
        array $findingIds = [],
        ?string $followUpRef = null,
    ): RunLearningDecision {
        $decision = RunLearningDecisionStatus::tryFrom($decisionValue)
            ?? throw new RuntimeException('Unknown finish learning decision: ' . $decisionValue . '.');
        $decidedBy = trim($decidedBy);
        $reason = trim($reason);
        if ($decidedBy === '' || $reason === '') {
            throw new RuntimeException('Finish learning disposition requires --by and --learning-reason.');
        }
        if ($run->taskId !== $contract->taskId || $session->taskId !== $run->taskId) {
            throw new RuntimeException('Finish learning disposition does not match the governed task lineage.');
        }

        $boundary = PostExecutionEvidenceBoundary::inspect($this->rootPath, $contract, $session);
        $validationSha256 = $boundary->validationEvidenceSha256();
        $reviewSha256 = $boundary->reviewEvidenceSha256();
        if ($validationSha256 === null || $reviewSha256 === null) {
            throw new RuntimeException('Finish learning disposition requires current validation and review evidence.');
        }

        $acknowledgement = (new ReviewAcknowledgementStore($this->rootPath))->find($run->taskId);
        if (
            $acknowledgement === null
            || $acknowledgement->runId !== $run->runId
            || $acknowledgement->contractRevision !== $contract->revision
            || !hash_equals($acknowledgement->implementationSnapshot, $boundary->implementation->digest)
            || !hash_equals($acknowledgement->reportSha256, $reviewSha256)
        ) {
            throw new RuntimeException('Finish learning disposition requires acknowledgement of the exact current review report.');
        }

        $learningRoot = WorkflowLearningRoot::forRun($this->rootPath, $run);
        if ($findingIds !== []) {
            $validated = (new LearningRepositoryValidator())->validate($learningRoot);
            foreach ($findingIds as $findingId) {
                $finding = $validated->findingsById[$findingId] ?? null;
                if ($finding === null) {
                    throw new RuntimeException('Learning Finding does not exist in the owner repository: ' . $findingId . '.');
                }
                if ($finding->taskId !== $run->taskId) {
                    throw new RuntimeException('Learning Finding belongs to another task: ' . $findingId . '.');
                }
            }
        }

        return (new RunLearningDecisionStore($learningRoot))->record(
            $run->runId,
            $decision,
            $decidedBy,
            $reason,
            $findingIds,
            $followUpRef,
            $contract->revision,
            $boundary->implementation->digest,
            $validationSha256,
            $reviewSha256,
        );
    }
}
