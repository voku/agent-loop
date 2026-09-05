<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Dogfood;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\LearningNoteContent;
use voku\AgentLearning\LearningNoteDraft;
use voku\AgentLearning\LearningNoteRepositoryEvidence;
use voku\AgentLearning\LearningNoteService;
use voku\AgentLearning\RecordIdGenerator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentSession\SessionStore;

/**
 * End-to-end dogfood proof for #349:
 * "run one teaches, transient context disappears, run two remembers".
 *
 * Task A completes, records a validated finding, and authors an active LearningNote.
 * Transient session context is retired and not shared.
 * Task B enters normally via `agent-loop enter` without manual parameter coaching,
 * and Recall deterministically supplies the precedent from Task A.
 */
final class LearningNoteTwoRunDogfoodTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-two-run-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning/findings/validated', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning/notes/active', 0o775, true);

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
        $this->removeDirectory($this->root);
    }

    public function testRunOneTeachesAndRunTwoRemembersPrecedentWithoutTransientContext(): void
    {
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

        // Finish step 1: prepare review
        $finishPrep = $this->runApp($app1, 'finish', [$taskA, '--format=json']);
        self::assertSame(1, $finishPrep['exit']);
        $reviewReport = (new WorkflowReviewReportReader($this->root))->read($taskA);
        $reviewSha = $reviewReport['sha256'] ?? null;
        self::assertIsString($reviewSha);

        // Record a validated Finding classified as ADD_LEARNING_NOTE bound to the active run/session lineage
        $runA = (new GovernedRunStore($this->root))->find($taskA);
        self::assertNotNull($runA);
        $sessionA = (new SessionStore())->activeForTask($this->root . '/.agent-loop/sessions', $taskA);
        self::assertNotNull($sessionA);
        $runLearningRoot = WorkflowLearningRoot::forRun($this->root, $runA);
        if (!is_dir($runLearningRoot . '/findings/validated')) { mkdir($runLearningRoot . '/findings/validated', 0o775, true); }

        $findingId = (new RecordIdGenerator())->generate('finding');
        file_put_contents(
            $runLearningRoot . '/findings/validated/' . $findingId . '.json',
            json_encode([
                'schema_version' => '1.0',
                'id' => $findingId,
                'task_id' => $taskA,
                'session' => $sessionA->id,
                'created_at' => '2026-09-04T00:00:00+00:00',
                'created_by' => 'security-lead',
                'scope' => ['src/AuthService.php'],
                'tags' => ['security', 'auth'],
                'observation' => 'Variable-time string comparisons leak token length and prefix timing.',
                'evidence' => [[
                    'type' => 'manual_verification',
                    'summary' => 'Benchmarked comparison timing across mismatched token lengths.',
                ]],
                'hypothesis' => 'Constant-time comparison via hash_equals prevents timing side channels.',
                'validated_conclusion' => 'Always use hash_equals for comparing secret tokens.',
                'confidence' => 'high',
                'validation_status' => 'validated',
                'status' => 'validated',
                'sensitivity' => 'public',
                'classification' => 'ADD_LEARNING_NOTE',
                'pattern_key' => 'auth.timing_leak_prevention',
                'validation_case' => [
                    'given' => 'Two authentication tokens to compare.',
                    'when' => 'hash_equals is used.',
                    'then' => 'Comparison executes in constant time regardless of match prefix.',
                ],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        // Finish step 2: close with learning disposition
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
        self::assertSame([[
            'kind' => 'learning_note',
            'finding_ids' => [$findingId],
            'skill' => 'agent-learning-note',
        ]], $finishClose['payload']['optional_follow_ups'] ?? null);

        // Publish active LearningNote from the validated finding in the global learning store
        $globalLearningRoot = $this->root . '/.agent-loop/learning';
        $learningService = new LearningNoteService();
        $sourceSha = (string) hash_file('sha256', $this->root . '/src/AuthService.php');
        $publishedNote = $learningService->publish(
            $globalLearningRoot,
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
        self::assertNotEmpty($publishedNote->id);

        // -------------------------------------------------------------------------
        // TRANSIENT CONTEXT DISAPPEARS
        // -------------------------------------------------------------------------
        // Discard all in-memory state: Run 1 session is finished and not reused.
        unset($dispatcher1, $recallRunner1, $app1, $enter1, $finishPrep, $finishClose);

        // -------------------------------------------------------------------------
        // RUN TWO (Task B: "Remembers")
        // -------------------------------------------------------------------------
        $taskB = 'TASK-349-RECALL';
        $contracts->create(
            $taskB,
            'Implement API token validation endpoint.',
            ['src/AuthService.php'],
            ['auth'],
            ['php -l src/AuthService.php'],
            'api-developer',
        );
        $contracts->approve($taskB, 'security-approver');

        $dispatcher2 = new Dispatcher($this->root);
        $recallRunner2 = static fn (array $recallRest): int => $dispatcher2->run(array_values([
            'agent-loop',
            'recall',
            ...$recallRest,
        ]));
        $app2 = new HostFrontDoorApplication($this->root, $recallRunner2);

        // Standard enter invocation without ANY manual precedent or learning flags:
        $enter2 = $this->runApp($app2, 'enter', [$taskB, '--format=json']);
        self::assertSame(0, $enter2['exit'], json_encode($enter2['payload'], JSON_THROW_ON_ERROR));

        // -------------------------------------------------------------------------
        // VERIFY PRECEDENT SUPPLY
        // -------------------------------------------------------------------------
        $recallDir = $this->root . '/.agent-loop/recall/' . $taskB;
        $recallOutput = (new CompiledRecallOutputReader())->read($recallDir);
        self::assertNotNull($recallOutput);
        self::assertTrue($recallOutput->hasFacts());

        // Prove that Recall compiled the LearningNote precedent fact
        $precedentFacts = array_values(array_filter(
            $recallOutput->facts(),
            static fn ($fact): bool => $fact->type === 'learning_precedent',
        ));
        self::assertCount(1, $precedentFacts);
        self::assertSame($publishedNote->id, $precedentFacts[0]->payload['note_id']);
        self::assertSame('auth.timing_leak_prevention', $precedentFacts[0]->payload['pattern_key']);
        self::assertContains('scope_match', $precedentFacts[0]->payload['match_reasons'] ?? []);

        // Prove that the precedent guidance was projected into the system prompt
        $promptAugmentationPath = $recallDir . '/system.md';
        self::assertFileExists($promptAugmentationPath);
        $promptAugmentation = (string) file_get_contents($promptAugmentationPath);
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

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
