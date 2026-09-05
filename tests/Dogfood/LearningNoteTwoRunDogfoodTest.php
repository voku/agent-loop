<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Dogfood;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SplFileInfo;
use voku\AgentLearning\FindingCreator;
use voku\AgentLearning\LearningClassification;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\ValidationCase;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

/**
 * End-to-end dogfood proof for #349:
 * "run one teaches, transient context disappears, run two remembers".
 *
 * Task A completes, records a validated Finding through the released Learning owner API,
 * and exposes LearningNote authoring only as an optional post-close follow-up. Its Session
 * is then physically pruned before the note is published from durable Learning evidence.
 * Task B enters normally via `agent-loop enter` without manual precedent coaching, and
 * Recall deterministically supplies the exact precedent and its source lineage.
 */
final class LearningNoteTwoRunDogfoodTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-two-run-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);

        file_put_contents(
            $this->root . '/src/AuthService.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

final class AuthService
{
    public function verifyToken(string $knownToken, string $userToken): bool
    {
        return hash_equals($knownToken, $userToken);
    }
}
PHP
        );

        file_put_contents(
            $this->root . '/composer.json',
            json_encode([
                'name' => 'dogfood/two-run-precedent',
                'autoload' => [
                    'psr-4' => [
                        'App\\' => 'src/',
                    ],
                ],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $directories = [$this->root];
        for ($index = 0; $index < count($directories); ++$index) {
            foreach (new FilesystemIterator($directories[$index], FilesystemIterator::SKIP_DOTS) as $item) {
                if (!$item instanceof SplFileInfo) {
                    throw new RuntimeException('FilesystemIterator did not return file metadata.');
                }
                if ($item->isDir() && !$item->isLink()) {
                    $directories[] = $item->getPathname();
                    continue;
                }
                unlink($item->getPathname());
            }
        }
        foreach (array_reverse($directories) as $directory) {
            rmdir($directory);
        }
    }

    public function testRunOneTeachesAndRunTwoRemembersPrecedentWithoutTransientContext(): void
    {
        $layout = new ProjectLayout($this->root);

        // -------------------------------------------------------------------------
        // RUN ONE (Task A: "Teaches")
        // -------------------------------------------------------------------------
        $contracts = new TaskContractStore($this->root);
        $taskA = 'TASK-349-TEACH';
        $contracts->create(
            $taskA,
            'Audit and fix token comparison timing leak.',
            ['src/AuthService.php'],
            ['security', 'auth'],
            ['php -l src/AuthService.php'],
            'security-lead',
        );
        $contracts->approve($taskA, 'security-approver');

        $dispatcher1 = new Dispatcher($this->root);
        $recallRunner1 = static fn (array $recallRest): int => $dispatcher1->run(array_values([
            'agent-loop',
            'recall',
            ...$recallRest,
        ]));
        $app1 = new HostFrontDoorApplication($this->root, $recallRunner1);

        $enter1 = $this->runApp($app1, 'enter', [$taskA, '--format=json']);
        self::assertSame(0, $enter1['exit'], json_encode($enter1['payload'], JSON_THROW_ON_ERROR));

        // Finish step 1: prepare review.
        $finishPrep = $this->runApp($app1, 'finish', [$taskA, '--format=json']);
        self::assertSame(1, $finishPrep['exit']);
        $reviewReport = (new WorkflowReviewReportReader($this->root))->read($taskA);
        $reviewSha = $reviewReport['sha256'] ?? null;
        self::assertIsString($reviewSha);

        // Record a classified Finding through Learning's public owner mutation.
        $runA = (new GovernedRunStore($this->root))->find($taskA);
        self::assertNotNull($runA);
        $sessionStore = new SessionStore();
        $sessionA = $sessionStore->activeForTask($layout->sessionsRoot(), $taskA);
        self::assertNotNull($sessionA);
        $learningRoot = WorkflowLearningRoot::forRun($this->root, $runA);

        $finding = (new FindingCreator())->createValidated(
            root: $learningRoot,
            taskId: $taskA,
            session: $sessionA->id,
            createdBy: 'security-lead',
            scope: ['src/AuthService.php'],
            observation: 'Variable-time string comparisons leak token length and prefix timing.',
            evidence: [[
                'type' => 'manual_verification',
                'summary' => 'Benchmarked comparison timing across mismatched token lengths.',
            ]],
            hypothesis: 'Constant-time comparison via hash_equals prevents timing side channels.',
            validatedConclusion: 'Always use hash_equals for comparing secret tokens.',
            confidence: 'high',
            sensitivity: 'public',
            classification: LearningClassification::ADD_LEARNING_NOTE,
            patternKey: 'auth.timing_leak_prevention',
            validationCase: new ValidationCase(
                given: 'Two authentication tokens to compare.',
                when: 'hash_equals is used.',
                then: 'Comparison executes in constant time regardless of match prefix.',
            ),
        );
        $findingId = $finding->finding->id;

        // Learning can prepare the optional note using only its durable owner state.
        $learningService = new LearningNoteService();
        $preparedNote = $learningService->prepare($learningRoot, [$findingId], $this->root);
        self::assertSame('auth.timing_leak_prevention', $preparedNote->patternKey);
        self::assertSame([$findingId], array_column($preparedNote->findings, 'id'));

        // Finish step 2: close with the existing Learning disposition.
        $finishClose = $this->runApp($app1, 'finish', [
            $taskA,
            '--format=json',
            '--reviewed-report-sha256',
            $reviewSha,
            '--learning',
            'findings_recorded',
            '--learning-reason',
            'Constant-time token verification is a reusable security precedent.',
            '--by',
            'security-approver',
            '--finding',
            $findingId,
        ]);

        self::assertSame(0, $finishClose['exit'], json_encode($finishClose['payload'], JSON_THROW_ON_ERROR));
        self::assertTrue($finishClose['payload']['complete'] ?? false);
        self::assertSame('none', $finishClose['payload']['next_action_kind'] ?? null);
        self::assertSame([[
            'kind' => 'learning_note',
            'finding_ids' => [$findingId],
            'skill' => 'agent-learning-note',
        ]], $finishClose['payload']['optional_follow_ups'] ?? null);

        // The software Run is complete. Physically remove Task A working memory before
        // authoring the note to prove the return loop does not depend on Session/chat state.
        $removedSessions = $sessionStore->prune($layout->sessionsRoot(), 0, [SessionStatus::DONE]);
        self::assertContains($sessionA->id, $removedSessions);
        self::assertDirectoryDoesNotExist($sessionA->path);

        unset(
            $contracts,
            $dispatcher1,
            $recallRunner1,
            $app1,
            $enter1,
            $finishPrep,
            $finishClose,
            $reviewReport,
            $runA,
            $sessionA,
        );

        // Author the optional precedent only from the durable Finding through Learning's
        // public owner API. No Session object or Learning-private storage path is available.
        $sourceSha = (string) hash_file('sha256', $this->root . '/src/AuthService.php');
        $publishedNote = $learningService->publish(
            $learningRoot,
            new LearningNoteDraft(
                sourceFindings: [$findingId],
                sourceProposals: [],
                tags: ['security', 'auth'],
                repositoryEvidence: [
                    new LearningNoteRepositoryEvidence('src/AuthService.php', $sourceSha),
                ],
                content: new LearningNoteContent(
                    title: 'Constant-time auth token comparison',
                    context: 'Comparing user-supplied tokens against stored secrets.',
                    guidance: 'Always compare secret tokens with hash_equals() instead of == or ===.',
                    whyItWorks: 'hash_equals() runs in constant time, preventing timing attacks.',
                    whenToApply: 'Whenever comparing authentication tokens, API keys, or password hashes.',
                    whenNotToApply: 'Non-sensitive string comparisons where timing leaks pose no risk.',
                    verification: 'Run phpunit security tests.',
                    symptoms: 'Execution time varies based on how many characters match.',
                    failedApproaches: ['Using standard === string comparison.'],
                    rootCause: 'Standard string comparison returns false early on first mismatched byte.',
                    examples: ['hash_equals($knownToken, $userToken)'],
                ),
            ),
            $this->root,
        );
        self::assertSame([$findingId], $publishedNote->sourceFindings);

        // -------------------------------------------------------------------------
        // RUN TWO (Task B: "Remembers")
        // -------------------------------------------------------------------------
        $taskB = 'TASK-349-RECALL';
        $contracts2 = new TaskContractStore($this->root);
        $contracts2->create(
            $taskB,
            'Implement API token validation endpoint.',
            ['src/AuthService.php'],
            ['auth'],
            ['php -l src/AuthService.php'],
            'api-developer',
        );
        $contracts2->approve($taskB, 'security-approver');

        $dispatcher2 = new Dispatcher($this->root);
        $recallRunner2 = static fn (array $recallRest): int => $dispatcher2->run(array_values([
            'agent-loop',
            'recall',
            ...$recallRest,
        ]));
        $app2 = new HostFrontDoorApplication($this->root, $recallRunner2);

        // Standard enter invocation without any manual precedent/Learning preparation.
        $enter2 = $this->runApp($app2, 'enter', [$taskB, '--format=json']);
        self::assertSame(0, $enter2['exit'], json_encode($enter2['payload'], JSON_THROW_ON_ERROR));

        // -------------------------------------------------------------------------
        // VERIFY PRECEDENT SUPPLY + PROVENANCE
        // -------------------------------------------------------------------------
        $recallDir = $layout->recallRoot() . '/' . $taskB;
        $recallOutput = (new CompiledRecallOutputReader())->read($recallDir);
        self::assertNotNull($recallOutput);
        self::assertTrue($recallOutput->hasFacts());

        $precedentFacts = array_values(array_filter(
            $recallOutput->facts(),
            static fn ($fact): bool => $fact->type === 'learning_precedent',
        ));
        self::assertCount(1, $precedentFacts);
        $precedent = $precedentFacts[0];

        self::assertSame($publishedNote->id, $precedent->payload['note_id']);
        self::assertSame('auth.timing_leak_prevention', $precedent->payload['pattern_key']);
        self::assertSame('agent-learning:' . $publishedNote->id, $precedent->sourceRef);
        self::assertSame([$findingId], $precedent->payload['source_findings']);
        self::assertSame($publishedNote->digest, $precedent->payload['note_digest']);
        self::assertSame('current', $precedent->payload['evidence_state']);
        self::assertTrue($precedent->payload['render']);
        self::assertContains('scope_match', $precedent->payload['match_reasons'] ?? []);

        $promptAugmentationPath = $recallDir . '/system.md';
        self::assertFileExists($promptAugmentationPath);
        $promptAugmentation = (string) file_get_contents($promptAugmentationPath);
        self::assertStringContainsString('Relevant Learning Precedents', $promptAugmentation);
        self::assertStringContainsString('Constant-time auth token comparison', $promptAugmentation);
        self::assertStringContainsString('hash_equals', $promptAugmentation);
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function runApp(HostFrontDoorApplication $app, string $command, array $args): array
    {
        ob_start();
        try {
            $exit = $app->run($command, $args);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Application JSON did not decode to an object: ' . $stdout);
        }

        /** @var array<string, mixed> $payload */
        return ['exit' => $exit, 'payload' => $payload];
    }
}
