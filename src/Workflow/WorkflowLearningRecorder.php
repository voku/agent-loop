<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLearning\FindingCreator;
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

    /** @param list<FinishFindingInput> $findingInputs */
    public function record(
        GovernedRun $run,
        TaskContract $contract,
        Session $session,
        string $decisionValue,
        string $decidedBy,
        string $reason,
        array $findingInputs = [],
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
        if ($findingInputs !== [] && $decision !== RunLearningDecisionStatus::FINDINGS_RECORDED) {
            throw new RuntimeException('Finish Finding content is only valid with --learning findings_recorded.');
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
        $findingIds = [];
        if ($findingInputs !== []) {
            $creator = new FindingCreator();
            $evidence = $this->findingEvidence($boundary, $reviewSha256);
            foreach ($findingInputs as $input) {
                $created = $creator->createValidated(
                    root: $learningRoot,
                    taskId: $run->taskId,
                    session: $session->id,
                    createdBy: $decidedBy,
                    scope: $contract->scope,
                    observation: $input->observation,
                    evidence: $evidence,
                    hypothesis: $input->hypothesis,
                    validatedConclusion: $input->validatedConclusion,
                    confidence: $input->confidence,
                    sensitivity: $input->sensitivity,
                );
                $findingIds[] = $created->finding->id;
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

    /**
     * @return list<array<string, mixed>>
     */
    private function findingEvidence(PostExecutionEvidenceBoundary $boundary, string $reviewSha256): array
    {
        $evidence = [];
        foreach ($boundary->validationEvidence as $validation) {
            if ($validation === null) {
                continue;
            }
            $evidence[] = [
                'type' => 'test_result',
                'command' => $validation->command,
                'summary' => sprintf(
                    'Observed %s with exit code %d for implementation %s.',
                    $validation->status->value,
                    $validation->exitCode,
                    $validation->implementationSnapshot ?? 'unbound',
                ),
            ];
        }
        $evidence[] = [
            'type' => 'manual_verification',
            'summary' => 'Exact blind-spot report acknowledged: ' . $reviewSha256 . '.',
        ];

        return $evidence;
    }
}
