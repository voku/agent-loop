<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLearning\FindingRepository;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;

/** Projects optional post-close LearningNote authoring work from Learning-owned state. */
final readonly class HostLearningNoteFollowUpProjector
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return list<array{kind: 'learning_note', finding_ids: list<non-empty-string>, skill: 'agent-learning-note'}>
     */
    public function project(string $taskId): array
    {
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        if ($run === null) {
            return [];
        }

        $learningRoot = WorkflowLearningRoot::forRun($this->rootPath, $run);
        $decision = (new RunLearningDecisionStore($learningRoot))->find($run->runId);
        if ($decision === null || $decision->decision !== RunLearningDecisionStatus::FINDINGS_RECORDED) {
            return [];
        }

        /** @var list<non-empty-string> $candidateIds */
        $candidateIds = [];
        $findings = (new FindingRepository())->loadAll($learningRoot);
        foreach ($decision->findingIds as $findingId) {
            $finding = $findings[$findingId] ?? null;
            if ($finding === null) {
                throw new RuntimeException(
                    'Run Learning decision references missing Finding ' . $findingId . '.',
                );
            }
            if ($finding->classification !== LearningClassification::ADD_LEARNING_NOTE) {
                continue;
            }
            $candidateIds[] = $this->nonEmptyFindingId($finding->id);
        }

        sort($candidateIds, SORT_STRING);
        if ($candidateIds === []) {
            return [];
        }

        return [[
            'kind' => 'learning_note',
            'finding_ids' => $candidateIds,
            'skill' => 'agent-learning-note',
        ]];
    }

    /** @return non-empty-string */
    private function nonEmptyFindingId(string $findingId): string
    {
        $findingId = trim($findingId);
        if ($findingId === '') {
            throw new RuntimeException('Learning owner returned an empty Finding id.');
        }

        return $findingId;
    }
}
