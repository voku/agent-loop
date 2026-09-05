<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\PostExecutionEvidenceBoundary;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class HostFrontDoorCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-host-front-door-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create test root: ' . $this->root);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testEnterMissingWorkflowIsReadOnlyAndPointsToCanonicalPlanAction(): void
    {
        $before = $this->snapshotFiles();
        $result = $this->runBinary(['enter', 'ABC-123', '--format=json']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        $payload = $this->json($result['stdout']);
        self::assertSame('enter', $payload['command']);
        self::assertFalse($payload['mutation_ready']);
        self::assertSame('legacy_inferred', $payload['manifest']['mode']);
        self::assertStringContainsString('workflow plan ABC-123', $payload['next_action']);
        self::assertSame($before, $this->snapshotFiles(), 'enter must not create or modify workflow state.');
    }

    public function testEnterTextShowsTheFullCandidateGoalBeforeApprovalAction(): void
    {
        $goal = 'Show this complete goal before an approver is asked to decide.';
        (new TaskContractStore($this->root))->create(
            'TEXT-1',
            $goal,
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'fixture-planner',
        );

        $result = $this->runBinary(['enter', 'TEXT-1']);

        self::assertSame(1, $result['exit'], $result['stderr']);
        $goalOffset = strpos($result['stdout'], "Approval subject:\n  Contract revision: 1\n  Goal:\n    " . $goal);
        $nextOffset = strpos($result['stdout'], 'Next: agent-loop workflow approve TEXT-1 --by <named-actor>');
        self::assertNotFalse($goalOffset, $result['stdout']);
        self::assertNotFalse($nextOffset, $result['stdout']);
        self::assertLessThan($nextOffset, $goalOffset, $result['stdout']);
    }

    public function testEnterJsonRemainsOneDocumentWhileRecallIsPrepared(): void
    {
        $docsDirectory = $this->root . '/docs';
        if (!mkdir($docsDirectory, 0o775, true) && !is_dir($docsDirectory)) {
            throw new RuntimeException('Unable to create docs directory.');
        }
        file_put_contents($docsDirectory . '/note.txt', "current\n");
        if (!mkdir($this->root . '/.agent-loop/learning', 0o775, true) && !is_dir($this->root . '/.agent-loop/learning')) {
            throw new RuntimeException('Unable to create learning root.');
        }

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'JSON-1',
            'Prepare one bounded text change.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'lars',
        );
        $contracts->approve('JSON-1', 'lars');

        $result = $this->runBinary(['enter', 'JSON-1', '--format=json']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        $payload = $this->json($result['stdout']);
        self::assertSame('enter', $payload['command']);
        self::assertSame('JSON-1', $payload['task_id']);
        self::assertTrue($payload['mutation_ready']);
        self::assertSame('compiled', $payload['manifest']['references']['recall']['state']);
        self::assertFileExists($this->root . '/.agent-loop/recall/JSON-1/meta.json');
    }

    public function testEnterReportsMutationReadyFromExistingGovernedOwnerArtifacts(): void
    {
        $this->prepareGovernedRun();
        $before = $this->snapshotFiles();

        $result = $this->runBinary(['enter', 'ABC-123', '--format=json', '--max-lines=40', '--max-bytes=4096']);

        self::assertSame(0, $result['exit'], $result['stderr']);
        $payload = $this->json($result['stdout']);
        self::assertTrue($payload['mutation_ready']);
        self::assertSame('governed', $payload['manifest']['mode']);
        self::assertSame('approved', $payload['manifest']['references']['contract']['state']);
        self::assertSame('current', $payload['manifest']['references']['approval']['state']);
        self::assertSame('active', $payload['manifest']['references']['session']['state']);
        self::assertSame('compiled', $payload['manifest']['references']['recall']['state']);
        self::assertSame('not_required', $payload['manifest']['references']['execution_contract']['state']);
        self::assertSame($payload['manifest']['next_action'], $payload['next_action']);
        self::assertSame('ABC-123', $payload['context']['task_id']);
        self::assertLessThanOrEqual(40, count($payload['context']['lines']));
        self::assertSame($before, $this->snapshotFiles(), 'enter must not modify governed workflow artifacts.');
    }

    public function testReadyToCloseCannotReopenMutationAndFinishClosesOrdinaryRun(): void
    {
        [, $session] = $this->prepareGovernedRun(withCloseEvidence: true);
        $before = $this->snapshotFiles();

        $entry = $this->runBinary(['enter', 'ABC-123', '--format=json']);

        self::assertSame(0, $entry['exit'], $entry['stderr']);
        $entryPayload = $this->json($entry['stdout']);
        self::assertFalse($entryPayload['mutation_ready']);
        self::assertSame('ready_to_close', $entryPayload['manifest']['state']);
        self::assertSame($before, $this->snapshotFiles(), 'enter must stay read-only and must not reopen mutation after verification.');

        $ready = $this->runBinary(['finish', 'ABC-123', '--format=json']);

        self::assertSame(0, $ready['exit'], $ready['stderr']);
        $readyPayload = $this->json($ready['stdout']);
        self::assertTrue($readyPayload['complete']);
        self::assertSame('complete', $readyPayload['manifest']['state']);
        self::assertSame('none', $readyPayload['next_action']);
        self::assertSame(
            SessionStatus::DONE,
            (new SessionStore())->load($this->root . '/.agent-loop/sessions', $session->id)->status,
        );
        self::assertNotNull((new RunVerificationReceiptStore($this->root))->find('ABC-123'));

        $afterClose = $this->snapshotFiles();
        $complete = $this->runBinary(['finish', 'ABC-123', '--format=json']);

        self::assertSame(0, $complete['exit'], $complete['stderr']);
        $completePayload = $this->json($complete['stdout']);
        self::assertTrue($completePayload['complete']);
        self::assertSame('complete', $completePayload['manifest']['state']);
        self::assertSame('none', $completePayload['next_action']);
        self::assertSame($afterClose, $this->snapshotFiles(), 'finish must be read-only after completion.');
    }

    public function testQuickRequiresGoalAndFile(): void
    {
        $result = $this->runBinary(['quick']);
        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('requires a goal description', $result['stderr']);

        $resultNoFile = $this->runBinary(['quick', 'Fix a typo']);
        self::assertSame(1, $resultNoFile['exit']);
        self::assertStringContainsString('requires at least one --file', $resultNoFile['stderr']);
    }

    public function testQuickCreatesApprovedContractPreparesRunAndEnters(): void
    {
        $sourceDirectory = $this->root . '/src';
        if (!mkdir($sourceDirectory, 0o775, true) && !is_dir($sourceDirectory)) {
            throw new RuntimeException('Unable to create source directory.');
        }
        file_put_contents($sourceDirectory . '/QuickTarget.php', "<?php\nfinal class QuickTarget {}\n");
        if (!mkdir($this->root . '/.agent-loop/learning', 0o775, true) && !is_dir($this->root . '/.agent-loop/learning')) {
            throw new RuntimeException('Unable to create learning directory.');
        }

        $result = $this->runBinary([
            'quick',
            'QUICK-1',
            'Fix typo in QuickTarget',
            '--file=src/QuickTarget.php',
            '--verify=php -l src/QuickTarget.php',
            '--format=json',
        ]);

        self::assertSame(0, $result['exit'], $result['stderr']);
        $payload = $this->json($result['stdout']);
        self::assertSame('quick', $payload['command']);
        self::assertSame('QUICK-1', $payload['task_id']);
        self::assertTrue($payload['mutation_ready']);
        self::assertSame(['src/QuickTarget.php'], $payload['scope']);
        self::assertContains('fast_path', (new TaskContractStore($this->root))->load('QUICK-1')->tags);
    }

    public function testQuickFinishAutoClosesCleanFastPath(): void
    {
        $sourceDirectory = $this->root . '/src';
        if (!mkdir($sourceDirectory, 0o775, true) && !is_dir($sourceDirectory)) {
            throw new RuntimeException('Unable to create source directory.');
        }
        file_put_contents($sourceDirectory . '/QuickTarget.php', "<?php\nfinal class QuickTarget {}\n");
        if (!mkdir($this->root . '/.agent-loop/learning', 0o775, true) && !is_dir($this->root . '/.agent-loop/learning')) {
            throw new RuntimeException('Unable to create learning directory.');
        }

        $quickResult = $this->runBinary([
            'quick',
            'QUICK-FINISH-1',
            'Fix docblock in QuickTarget',
            '--file=src/QuickTarget.php',
            '--verify=php -r "exit(0);"',
            '--format=json',
        ]);
        self::assertSame(0, $quickResult['exit'], $quickResult['stderr']);

        $finishResult = $this->runBinary(['finish', 'QUICK-FINISH-1', '--format=json']);
        self::assertSame(0, $finishResult['exit'], $finishResult['stderr']);
        $finishPayload = $this->json($finishResult['stdout']);
        self::assertTrue($finishPayload['complete'], $finishResult['stdout']);
        self::assertSame('complete', $finishPayload['manifest']['state']);
        self::assertSame('none', $finishPayload['next_action']);
    }

    public function testQuickFinishFailsClosedWhenScopeViolated(): void
    {
        $sourceDirectory = $this->root . '/src';
        if (!mkdir($sourceDirectory, 0o775, true) && !is_dir($sourceDirectory)) {
            throw new RuntimeException('Unable to create source directory.');
        }
        file_put_contents($sourceDirectory . '/QuickTarget.php', "<?php\nfinal class QuickTarget {}\n");
        if (!mkdir($this->root . '/.agent-loop/learning', 0o775, true) && !is_dir($this->root . '/.agent-loop/learning')) {
            throw new RuntimeException('Unable to create learning directory.');
        }

        exec('git -C ' . escapeshellarg($this->root) . ' init -b main 2>&1');
        exec('git -C ' . escapeshellarg($this->root) . ' config user.name "Test" 2>&1');
        exec('git -C ' . escapeshellarg($this->root) . ' config user.email "test@example.com" 2>&1');
        exec('git -C ' . escapeshellarg($this->root) . ' add src/QuickTarget.php 2>&1');
        exec('git -C ' . escapeshellarg($this->root) . ' commit -m "Initial commit" 2>&1');

        $quickResult = $this->runBinary([
            'quick',
            'QUICK-FAIL-1',
            'Fix docblock in QuickTarget',
            '--file=src/QuickTarget.php',
            '--verify=php -r "exit(0);"',
            '--format=json',
        ]);
        self::assertSame(0, $quickResult['exit'], $quickResult['stderr']);

        file_put_contents($sourceDirectory . '/Undeclared.php', "<?php\nfinal class Undeclared {}\n");

        $finishResult = $this->runBinary(['finish', 'QUICK-FAIL-1', '--format=json']);
        self::assertSame(1, $finishResult['exit']);
        $finishPayload = $this->json($finishResult['stdout']);
        self::assertFalse($finishPayload['complete']);
        self::assertStringContainsString('Fast-path scope violated: modified undeclared file(s): src/Undeclared.php', $finishResult['stdout']);
    }

    /** @return array{0: SessionStore, 1: Session} */
    private function prepareGovernedRun(bool $withCloseEvidence = false): array
    {
        $sourceDirectory = $this->root . '/src';
        if (!mkdir($sourceDirectory, 0o775, true) && !is_dir($sourceDirectory)) {
            throw new RuntimeException('Unable to create source directory.');
        }
        file_put_contents($sourceDirectory . '/Foo.php', "<?php\nfinal class Foo {}\n");

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'ABC-123',
            'Exercise the host front door.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'lars',
        );
        $contract = $contracts->approve('ABC-123', 'lars');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

        $sessions = new SessionStore();
        $session = $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $recallDirectory = $this->root . '/.agent-loop/recall/ABC-123';
        if (!mkdir($recallDirectory, 0o775, true) && !is_dir($recallDirectory)) {
            throw new RuntimeException('Unable to create recall directory.');
        }
        file_put_contents(
            $recallDirectory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-001',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );

        if (!$withCloseEvidence) {
            return [$sessions, $session];
        }

        (new ValidationEvidenceStore())->record(
            $session,
            $contract->revision,
            'vendor/bin/phpunit',
            ValidationStatus::PASSED,
            0,
            10,
            'lars',
            implementationSnapshot: $snapshot->digest,
        );

        $reviewDirectory = $recallDirectory . '/reviews';
        if (!mkdir($reviewDirectory, 0o775, true) && !is_dir($reviewDirectory)) {
            throw new RuntimeException('Unable to create review directory.');
        }
        file_put_contents(
            $reviewDirectory . '/ABC-123.blindspots.json',
            json_encode([
                'version' => 2,
                'task_id' => 'ABC-123',
                'status' => 'ok',
                'contract_revision' => $contract->revision,
                'implementation_snapshot' => $snapshot->digest,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
        );

        $review = (new WorkflowReviewReportReader($this->root))->read('ABC-123');
        self::assertSame('unacknowledged', $review['status']);
        self::assertNotNull($review['sha256']);
        (new ReviewAcknowledgementStore($this->root))->record(
            $run,
            $contract,
            $snapshot,
            $review['sha256'],
            'lars',
        );

        $boundary = PostExecutionEvidenceBoundary::inspect($this->root, $contract, $session);
        $validationSha256 = $boundary->validationEvidenceSha256();
        $reviewSha256 = $boundary->reviewEvidenceSha256();
        self::assertNotNull($validationSha256);
        self::assertNotNull($reviewSha256);
        (new RunLearningDecisionStore($this->root . '/.agent-loop/learning'))->record(
            $run->runId,
            RunLearningDecisionStatus::NO_DURABLE_LEARNING,
            'lars',
            'No durable learning from the front-door fixture.',
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
            validationEvidenceSha256: $validationSha256,
            reviewEvidenceSha256: $reviewSha256,
        );

        return [$sessions, $session];
    }

    /** @param list<string> $args
     *  @return array{exit: int, stdout: string, stderr: string}
     */
    private function runBinary(array $args): array
    {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', ...$args],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-loop binary.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @return array<string, mixed> */
    private function json(string $json): array
    {
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /** @return list<string> */
    private function snapshotFiles(): array
    {
        $files = [];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $hash = hash_file('sha256', $item->getPathname());
            if (!is_string($hash)) {
                throw new RuntimeException('Unable to hash test file: ' . $item->getPathname());
            }
            $files[] = str_replace($this->root . '/', '', $item->getPathname()) . ':' . $hash;
        }
        sort($files, SORT_STRING);

        return $files;
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
