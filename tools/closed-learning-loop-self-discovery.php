<?php

declare(strict_types=1);

use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;

require dirname(__DIR__) . '/vendor/autoload.php';

final class ClosedLearningLoopSelfDiscoveryFailure extends RuntimeException
{
}

final readonly class ClosedLearningLoopSelfDiscovery
{
    private const string FINDING_ID = 'finding.2026-09-12.457001';
    private const string NOTE_ID = 'learning-note.2026-09-12.457001';
    private const string PATTERN_KEY = 'workflow.closed_loop_requires_real_precedent_handoff';

    public function __construct(private string $repositoryRoot)
    {
    }

    public function run(): int
    {
        try {
            $learningRoot = $this->repositoryRoot . '/.agent-loop/learning';
            $service = new LearningNoteService();
            $notesBefore = $service->activeProjections($learningRoot, $this->repositoryRoot);

            if ($notesBefore !== []) {
                throw new ClosedLearningLoopSelfDiscoveryFailure(
                    'Expected the current agent-loop dogfood root to have no active LearningNotes before this experiment; got '
                    . count($notesBefore) . '.',
                );
            }

            $findingResult = (new FindingCreator())->createValidated(
                root: $learningRoot,
                taskId: 'GH-457',
                session: '2026-09-12-gh-457-closed-learning-loop-self-discovery',
                createdBy: 'agent-loop-self-discovery',
                scope: [
                    'tests/Dogfood/LearningNoteTwoRunDogfoodTest.php',
                    'tools/execution-contract-dogfood.php',
                ],
                observation: 'The repository proves the two deterministic halves of the learning thesis separately but has no durable LearningNote in its real .agent-loop/learning root. LearningNoteTwoRunDogfoodTest publishes a note only inside a temporary test root and proves Task B receives it in Recall system.md. execution-contract-dogfood.php proves an L2-gated task consumes a bound L1, but that L1 is authored as a literal heredoc by the harness rather than synthesized by a real coding-agent host. Therefore green mechanics do not yet prove that a persisted prior LearningNote changes a later real agent decision through Recall -> L2 -> L1 -> execution.',
                evidence: [
                    [
                        'type' => 'file_reference',
                        'path' => 'tests/Dogfood/LearningNoteTwoRunDogfoodTest.php',
                        'line' => 1,
                        'summary' => 'The two-run dogfood publishes its LearningNote in a temporary project and stops after asserting the precedent is present in Recall system.md.',
                    ],
                    [
                        'type' => 'file_reference',
                        'path' => 'tools/execution-contract-dogfood.php',
                        'line' => 1,
                        'summary' => 'The execution-contract dogfood writes the project-specific L1 as a harness-owned literal before recording it through workflow contract.',
                    ],
                    [
                        'type' => 'manual_verification',
                        'summary' => 'LearningNoteService::activeProjections() returned zero active notes for the real agent-loop learning root before this experiment.',
                    ],
                ],
                hypothesis: 'A stack can have correct Finding, Recall, L2-gate, and Constraint mechanics while still never exercising its core soft-learning product path in real durable owner state. Treating isolated green halves as closed-loop proof hides the only transition whose value depends on an actual coding agent: whether selected precedent changes the L1 and the resulting engineering decision.',
                validatedConclusion: 'Do not claim the closed learning loop from fixture-only precedent and a separately hand-authored L1. Persist at least one owner-published LearningNote in the consuming repository, trace it into a later independent task, and require observable L2 -> L1 -> host behavior before calling the soft-learning loop proven. Keep the behavioral result UNKNOWN when a real coding-agent host has not executed the handoff.',
                confidence: 'high',
                sensitivity: 'public',
                id: self::FINDING_ID,
                classification: LearningClassification::ADD_LEARNING_NOTE,
                patternKey: self::PATTERN_KEY,
                validationCase: new ValidationCase(
                    given: 'A consuming repository claims a closed learning loop and has separately green Finding/Recall and L2/L1 mechanics.',
                    when: 'Its real durable Learning root contains no owner-published LearningNote and no later real coding-agent run demonstrates precedent-driven behavior.',
                    then: 'The soft-learning product thesis remains unproven even though the component mechanics are green.',
                ),
            );

            $readiness = $service->promotionReadiness($learningRoot, self::FINDING_ID);
            if (!$readiness->promotable) {
                throw new ClosedLearningLoopSelfDiscoveryFailure(
                    'Owner reports the self-discovery Finding is not LearningNote-promotable: '
                    . implode(', ', $readiness->blockers),
                );
            }

            $preparation = $service->prepare($learningRoot, [self::FINDING_ID], $this->repositoryRoot);
            if ($preparation->patternKey !== self::PATTERN_KEY) {
                throw new ClosedLearningLoopSelfDiscoveryFailure('Learning owner changed the prepared pattern identity.');
            }

            $note = $service->publish(
                $learningRoot,
                new LearningNoteDraft(
                    sourceFindings: [self::FINDING_ID],
                    sourceProposals: [],
                    tags: ['dogfood', 'learning', 'workflow'],
                    repositoryEvidence: [
                        $this->repositoryEvidence('tests/Dogfood/LearningNoteTwoRunDogfoodTest.php'),
                        $this->repositoryEvidence('tools/execution-contract-dogfood.php'),
                    ],
                    content: new LearningNoteContent(
                        title: 'Closed-loop proof requires a real durable precedent handoff',
                        context: 'A learning workflow may prove Finding creation, precedent selection, L2 contract gating, and L1 execution in separate fixtures while never proving that prior durable experience changes a later real coding-agent decision.',
                        guidance: 'Before treating the learning loop as proven, require one owner-published LearningNote in the consuming repository and trace it through a later independent task from Learning selection into Recall, L2 construction, the exact governed L1, the acting host, and an observable engineering decision. Prompt inclusion alone is evidence of exposure, not usefulness.',
                        whyItWorks: 'The requirement joins the deterministic owner boundaries to the one probabilistic handoff that component tests cannot establish: whether a coding agent actually uses relevant precedent when constructing and executing project-specific work.',
                        whenToApply: 'Use this when validating an agent workflow, memory/learning system, prompt compiler, or other architecture whose product claim spans multiple independently tested stages.',
                        whenNotToApply: 'Do not require a real-host behavioral cohort merely to prove a local deterministic parser, schema, owner API, or storage invariant that does not claim changed agent behavior.',
                        verification: 'Check the consuming Learning root through LearningNoteService, identify the later task and selected precedent, preserve the Recall compilation identity and execution-contract SHA-256, verify the acting host received that exact L1, and record the concrete decision or avoided wrong work attributable to the precedent. If no real host ran, record UNKNOWN instead of success.',
                        failedApproaches: [
                            'Treating an ephemeral two-run fixture plus a separate hand-authored execution-contract dogfood as end-to-end behavioral proof.',
                        ],
                        examples: [
                            'tests/Dogfood/LearningNoteTwoRunDogfoodTest.php',
                            'tools/execution-contract-dogfood.php',
                            'voku/agent-loop#457',
                        ],
                    ),
                    id: self::NOTE_ID,
                ),
                $this->repositoryRoot,
            );

            $notesAfter = $service->activeProjections($learningRoot, $this->repositoryRoot);
            if (count($notesAfter) !== 1 || $notesAfter[0]->id !== self::NOTE_ID) {
                throw new ClosedLearningLoopSelfDiscoveryFailure('Owner did not project exactly the expected first active LearningNote.');
            }

            $report = [
                'schema_version' => '1.0',
                'issue' => 457,
                'finding' => [
                    'id' => $findingResult->finding->id,
                    'path' => $this->relativePath($findingResult->path),
                    'classification' => LearningClassification::ADD_LEARNING_NOTE->value,
                    'pattern_key' => self::PATTERN_KEY,
                    'promotion_readiness' => 'ready',
                ],
                'learning_note' => $note->toArray(),
                'active_notes_before' => 0,
                'active_notes_after' => count($notesAfter),
                'deterministic_boundary' => [
                    'finding_to_learning_note' => 'proven_in_this_run',
                    'learning_note_to_recall' => 'proven_by_existing_two_run_dogfood',
                    'l2_gate_to_exact_l1_execution' => 'proven_by_existing_execution_contract_dogfood',
                ],
                'behavioral_handoff' => [
                    'state' => 'unknown',
                    'reason' => 'This GitHub CI run does not execute a real Codex/Claude/OpenCode host that constructs L1 from the L2 briefing. No behavioral value is claimed from prompt inclusion alone.',
                    'next_required_evidence' => 'A later independent real task must select this precedent, carry it through L2 -> exact governed L1 -> acting host, and show one attributable engineering decision or avoided wrong work.',
                ],
                'deterministic_promotion' => [
                    'state' => 'not_justified',
                    'reason' => 'One newly materialized precedent and zero later usage outcomes do not justify compiling this lesson into a hard rule.',
                ],
                'result' => 'boundary_discovered',
            ];

            $build = $this->repositoryRoot . '/build';
            if (!is_dir($build) && !mkdir($build, 0775, true) && !is_dir($build)) {
                throw new ClosedLearningLoopSelfDiscoveryFailure('Unable to create build directory.');
            }
            file_put_contents(
                $build . '/closed-learning-loop-self-discovery.json',
                json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            );

            fwrite(STDOUT, "Closed learning-loop self-discovery: BOUNDARY_DISCOVERED\n");
            fwrite(STDOUT, 'Finding: ' . $findingResult->finding->id . "\n");
            fwrite(STDOUT, 'LearningNote: ' . $note->id . "\n");
            fwrite(STDOUT, "Behavioral handoff: UNKNOWN (real coding-agent host required)\n");

            return 0;
        } catch (Throwable $throwable) {
            fwrite(STDERR, 'Closed learning-loop self-discovery: FAILED - ' . $throwable->getMessage() . "\n");

            return 1;
        }
    }

    private function repositoryEvidence(string $relativePath): LearningNoteRepositoryEvidence
    {
        $path = $this->repositoryRoot . '/' . $relativePath;
        $digest = hash_file('sha256', $path);
        if (!is_string($digest)) {
            throw new ClosedLearningLoopSelfDiscoveryFailure('Unable to hash repository evidence: ' . $relativePath);
        }

        return new LearningNoteRepositoryEvidence($relativePath, $digest);
    }

    private function relativePath(string $absolutePath): string
    {
        $prefix = rtrim($this->repositoryRoot, '/\\') . '/';
        if (!str_starts_with($absolutePath, $prefix)) {
            return $absolutePath;
        }

        return substr($absolutePath, strlen($prefix));
    }
}

$repositoryRoot = realpath(dirname(__DIR__));
if (!is_string($repositoryRoot)) {
    fwrite(STDERR, "Unable to resolve repository root.\n");
    exit(2);
}

exit((new ClosedLearningLoopSelfDiscovery($repositoryRoot))->run());
