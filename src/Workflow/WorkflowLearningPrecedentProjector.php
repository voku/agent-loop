<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentRecallCompiler\LearningPrecedentPreview;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentRecallCompiler\Output\RenderedLearningPrecedent;
use voku\AgentRecallCompiler\RecallCompilationBlockedException;

/**
 * Names the LearningNotes Recall selects for a task where the host reads them.
 *
 * In the learning-effect experiment (docs/dogfood/2026-11-19-learning-effect-round2.md)
 * every governed agent fixed its design in the candidate Contract, while Recall
 * compiled the matching precedent only after approval and nothing the host
 * returned named it: 0 of 3 agents saw it. A candidate Contract therefore gets a
 * Recall-owned preview over its scope, so the planner and the approving human
 * see the precedent before approval; an approved Run lists what its compiled
 * briefing renders. Selection, ranking and budget stay Recall's. This is
 * historical precedent, not an instruction, and changes no lifecycle state.
 *
 * @phpstan-type PrecedentItem array{note_id: string, pattern_key: string|null, title: string|null, guidance: string|null, matching_task_files: list<string>}
 * @phpstan-type PrecedentProjection array{source: 'candidate_contract'|'compiled_recall', status: 'selected'|'eligible'|'blocked'|'unavailable', use: 'historical_precedent_not_instruction', message: string, precedents: list<PrecedentItem>}
 */
final readonly class WorkflowLearningPrecedentProjector
{
    public function __construct(
        private string $rootPath,
        private LearningPrecedentPreview $preview = new LearningPrecedentPreview(),
    ) {
    }

    /** @return PrecedentProjection|null null when there is no Contract scope or nothing was selected */
    public function project(string $taskId): ?array
    {
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        if ($contract === null || !in_array($contract->status, [TaskContract::CANDIDATE, TaskContract::APPROVED], true)) {
            return null;
        }

        $source = $contract->status === TaskContract::APPROVED ? 'compiled_recall' : 'candidate_contract';
        try {
            if ($source === 'compiled_recall') {
                $output = (new CompiledRecallOutputReader())->readForTask((new ProjectLayout($this->rootPath))->recallRoot(), $taskId);
                if ($output !== null) {
                    $rendered = $output->renderedLearningPrecedents();
                    if ($rendered === []) {
                        return null;
                    }

                    return $output->hasFinalLearningPrecedentSelection()
                        ? $this->selected('compiled_recall', $rendered)
                        : $this->selected('compiled_recall', $rendered, 'eligible');
                }
                // Approved but not compiled yet: the preview still names the precedent.
                $source = 'candidate_contract';
            }
            $precedents = $this->preview->forCandidateScope(
                (new ProjectLayout($this->rootPath))->learningRoot(),
                $taskId,
                $contract->scope,
                $contract->tags,
            );
        } catch (RecallCompilationBlockedException $exception) {
            return $this->withoutPrecedents(
                $source,
                'blocked',
                $exception->getMessage() . ' Recall compilation for this scope will block until the note is republished or retired.',
            );
        } catch (RuntimeException $exception) {
            // Advisory projection: report why precedents are unknown instead of
            // implying there are none, and leave the failure to the owners.
            return $this->withoutPrecedents($source, 'unavailable', 'Learning precedents could not be read: ' . $exception->getMessage());
        }

        return $precedents === [] ? null : $this->selected($source, $precedents);
    }

    /** @param PrecedentProjection $projection */
    public function renderText(array $projection): string
    {
        $text = "\nLearning precedent (" . str_replace('_', ' ', $projection['source']) . '): ' . $projection['message'] . "\n";
        foreach ($projection['precedents'] as $precedent) {
            $text .= '- ' . ($precedent['title'] ?? $precedent['note_id']) . ' [' . $precedent['note_id'] . "]\n";
            if ($precedent['guidance'] !== null) {
                $text .= '  ' . $precedent['guidance'] . "\n";
            }
        }

        return $text;
    }

    /**
     * @param 'candidate_contract'|'compiled_recall' $source
     * @param 'blocked'|'unavailable' $status
     * @return PrecedentProjection
     */
    private function withoutPrecedents(string $source, string $status, string $message): array
    {
        return [
            'source' => $source,
            'status' => $status,
            'use' => 'historical_precedent_not_instruction',
            'message' => $message,
            'precedents' => [],
        ];
    }

    /**
     * @param 'candidate_contract'|'compiled_recall' $source
     * @param non-empty-list<RenderedLearningPrecedent> $precedents
     * @param 'selected'|'eligible' $status eligible: the compiled selection report is unavailable,
     *        so these notes matched but whether the briefing rendered them is unknown
     * @return PrecedentProjection
     */
    private function selected(string $source, array $precedents, string $status = 'selected'): array
    {
        return [
            'source' => $source,
            'status' => $status,
            'use' => 'historical_precedent_not_instruction',
            'message' => match (true) {
                $source === 'candidate_contract' => 'Recall selected these LearningNotes for the candidate scope. Read them before approving the design; revise the Contract if the precedent changes it.',
                $status === 'eligible' => 'These LearningNotes matched this task, but Recall\'s final selection is unavailable, so whether the briefing rendered them is unknown. Read them before relying on the briefing.',
                default => 'Recall rendered these LearningNotes into this task\'s briefing. Check your change against them.',
            },
            'precedents' => array_map(static fn (RenderedLearningPrecedent $p): array => [
                'note_id' => $p->noteId,
                'pattern_key' => $p->patternKey,
                'title' => $p->title,
                'guidance' => $p->guidance,
                'matching_task_files' => $p->matchingTaskFiles,
            ], $precedents),
        ];
    }
}
