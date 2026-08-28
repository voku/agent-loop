<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

final class WorkflowSupersessionRecoveryTest extends TestCase
{
    private const string TASK_ID = 'SUPERSEDE-1';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-supersession-recovery-' . bin2hex(random_bytes(5));
        if (!mkdir($this->root . '/.agent-loop/learning', 0o775, true) && !is_dir($this->root . '/.agent-loop/learning')) {
            throw new RuntimeException('Unable to create test learning root.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testNewApprovedRevisionDoesNotExposeOldVerificationReceiptAsCurrent(): void
    {
        $contracts = $this->createApprovedContract('Govern revision one.');
        $firstEnter = $this->enter();
        self::assertSame(0, $firstEnter['exit']);
        self::assertTrue($firstEnter['payload']['mutation_ready'] ?? false);

        $contract = $contracts->load(self::TASK_ID);
        $run = (new GovernedRunStore($this->root))->find(self::TASK_ID);
        self::assertNotNull($run);
        $sessions = (new SessionStore())->all((new ProjectLayout($this->root))->sessionsRoot());
        self::assertCount(1, $sessions);

        (new RunVerificationReceiptStore($this->root))->record(
            $run,
            $contract,
            $sessions[0],
            'sha256:' . str_repeat('b', 64),
            'satisfied',
            [[
                'command' => 'composer ci',
                'status' => 'passed',
                'exit_code' => 0,
                'executed_at' => '2026-08-18T09:00:00+00:00',
                'recorded_by' => 'fixture',
                'duration_ms' => 10,
            ]],
        );

        $verified = (new RunManifestProjector($this->root))->project(self::TASK_ID);
        self::assertSame('passed', $verified->references['verification']['state'] ?? null);

        $contracts->revise(
            self::TASK_ID,
            'Govern revision two.',
            ['docs/note.txt'],
            [],
            ['composer ci'],
            'fixture-planner',
        );
        $contracts->approve(self::TASK_ID, 'fixture-approver');

        $superseded = (new RunManifestProjector($this->root))->project(self::TASK_ID);
        self::assertSame(2, $superseded->references['contract']['revision'] ?? null);
        self::assertSame(1, $superseded->references['contract']['run_revision'] ?? null);
        self::assertSame('pending_close', $superseded->references['verification']['state'] ?? null);
        self::assertSame('agent-loop enter ' . self::TASK_ID, $superseded->nextAction);
    }

    public function testFailedRecallArchiveCannotMakeSupersededBundleMutationReady(): void
    {
        $contracts = $this->createApprovedContract('Govern revision one.');
        $firstEnter = $this->enter();
        self::assertSame(0, $firstEnter['exit']);
        self::assertTrue($firstEnter['payload']['mutation_ready'] ?? false);

        $contracts->revise(
            self::TASK_ID,
            'Govern revision two.',
            ['docs/note.txt'],
            [],
            ['composer ci'],
            'fixture-planner',
        );
        $contracts->approve(self::TASK_ID, 'fixture-approver');

        $recallRoot = RecallOutputRoot::resolve($this->root);
        $permissions = fileperms($recallRoot);
        if (!is_int($permissions)) {
            throw new RuntimeException('Unable to read Recall root permissions.');
        }
        $originalMode = $permissions & 0o777;
        self::assertTrue(chmod($recallRoot, 0o555));
        set_error_handler(
            static fn (int $severity, string $message): bool => str_contains($message, 'rename('),
        );
        try {
            $failedEnter = $this->enter();
        } finally {
            restore_error_handler();
            if (!chmod($recallRoot, $originalMode)) {
                throw new RuntimeException('Unable to restore Recall root permissions.');
            }
        }

        self::assertSame(1, $failedEnter['exit']);
        self::assertFalse($failedEnter['payload']['mutation_ready'] ?? true);
        self::assertSame('enter.preparation_failed', $failedEnter['payload']['blockers'][0]['code'] ?? null);

        $stale = (new RunManifestProjector($this->root))->project(self::TASK_ID);
        self::assertSame(2, $stale->references['contract']['revision'] ?? null);
        self::assertSame(2, $stale->references['contract']['run_revision'] ?? null);
        self::assertSame('stale', $stale->references['recall']['state'] ?? null);
        self::assertSame('agent-loop enter ' . self::TASK_ID, $stale->nextAction);

        $retry = $this->enter();
        self::assertSame(0, $retry['exit']);
        self::assertTrue($retry['payload']['mutation_ready'] ?? false);
        self::assertSame('compiled', $retry['payload']['manifest']['references']['recall']['state'] ?? null);
    }

    public function testEnterReopensTheBoundSessionInsteadOfSealingTheRun(): void
    {
        $this->createApprovedContract('Govern revision one.');
        self::assertSame(0, $this->enter()['exit']);

        $sessionStore = new SessionStore();
        $sessionsRoot = (new ProjectLayout($this->root))->sessionsRoot();
        $sessions = $sessionStore->all($sessionsRoot);
        self::assertCount(1, $sessions);
        $boundId = $sessions[0]->id;

        // the Run finished once and closed its working memory
        $sessionStore->close($sessions[0], SessionStatus::DONE, 'first finish');
        self::assertSame(SessionStatus::DONE, $sessionStore->load($sessionsRoot, $boundId)->status);

        // re-entering for a follow-up change must not deadlock on that closed Session
        $reentered = $this->enter();
        self::assertSame(0, $reentered['exit'], json_encode($reentered, JSON_THROW_ON_ERROR));

        $resumed = $sessionStore->load($sessionsRoot, $boundId);
        self::assertSame(SessionStatus::ACTIVE, $resumed->status);
        self::assertSame($boundId, $resumed->id, 'the Run binds to this id, so re-entering must not allocate a new one');
        self::assertCount(1, $sessionStore->all($sessionsRoot), 'reopening must not leave a second Session behind');
    }

    public function testAFollowUpImplementationSupersedesTheReceiptInsteadOfSealingTheRun(): void
    {
        $contracts = $this->createApprovedContract('Govern revision one.');
        self::assertSame(0, $this->enter()['exit']);

        $contract = $contracts->load(self::TASK_ID);
        $run = (new GovernedRunStore($this->root))->find(self::TASK_ID);
        self::assertNotNull($run);
        $sessionStore = new SessionStore();
        $sessions = $sessionStore->all((new ProjectLayout($this->root))->sessionsRoot());
        $receipts = new RunVerificationReceiptStore($this->root);

        $obligations = [[
            'command' => 'composer ci',
            'status' => 'passed',
            'exit_code' => 0,
            'executed_at' => '2026-08-28T09:00:00+00:00',
            'recorded_by' => 'fixture',
            'duration_ms' => 10,
        ]];

        $first = $receipts->record($run, $contract, $sessions[0], 'sha256:' . str_repeat('a', 64), 'satisfied', $obligations);
        self::assertSame([], $first->supersedes);

        // the review gate demanded a follow-up change, so the same Run verifies a newer implementation
        $second = $receipts->record($run, $contract, $sessions[0], 'sha256:' . str_repeat('b', 64), 'satisfied', $obligations);

        self::assertSame('sha256:' . str_repeat('b', 64), $second->implementationSnapshot);
        self::assertCount(1, $second->supersedes, 'the replaced attestation must survive in the chain');
        self::assertSame('sha256:' . str_repeat('a', 64), $second->supersedes[0]['implementation_snapshot']);

        // and the chain keeps growing rather than collapsing to the latest pair
        $third = $receipts->record($run, $contract, $sessions[0], 'sha256:' . str_repeat('c', 64), 'satisfied', $obligations);
        self::assertCount(2, $third->supersedes);

        $reloaded = $receipts->find(self::TASK_ID);
        self::assertNotNull($reloaded);
        self::assertSame('sha256:' . str_repeat('c', 64), $reloaded->implementationSnapshot);
        self::assertCount(2, $reloaded->supersedes, 'the chain must survive a round-trip through disk');
    }

    public function testRecordingTheIdenticalReceiptTwiceStaysIdempotent(): void
    {
        $contracts = $this->createApprovedContract('Govern revision one.');
        self::assertSame(0, $this->enter()['exit']);

        $contract = $contracts->load(self::TASK_ID);
        $run = (new GovernedRunStore($this->root))->find(self::TASK_ID);
        self::assertNotNull($run);
        $sessions = (new SessionStore())->all((new ProjectLayout($this->root))->sessionsRoot());
        $receipts = new RunVerificationReceiptStore($this->root);

        $obligations = [[
            'command' => 'composer ci',
            'status' => 'passed',
            'exit_code' => 0,
            'executed_at' => '2026-08-28T09:00:00+00:00',
            'recorded_by' => 'fixture',
            'duration_ms' => 10,
        ]];

        $snapshot = 'sha256:' . str_repeat('d', 64);
        $receipts->record($run, $contract, $sessions[0], $snapshot, 'satisfied', $obligations);
        $again = $receipts->record($run, $contract, $sessions[0], $snapshot, 'satisfied', $obligations);

        self::assertSame([], $again->supersedes, 'an unchanged re-record must not grow the chain');
    }

    public function testReplacementRunArchivesThePriorVerificationReceipt(): void
    {
        $contracts = $this->createApprovedContract('Govern revision one.');
        $firstEnter = $this->enter();
        self::assertSame(0, $firstEnter['exit']);

        $contract = $contracts->load(self::TASK_ID);
        $runs = new GovernedRunStore($this->root);
        $firstRun = $runs->find(self::TASK_ID);
        self::assertNotNull($firstRun);
        $sessionStore = new SessionStore();
        $sessions = $sessionStore->all((new ProjectLayout($this->root))->sessionsRoot());
        self::assertCount(1, $sessions);
        (new RunVerificationReceiptStore($this->root))->record(
            $firstRun,
            $contract,
            $sessions[0],
            'sha256:' . str_repeat('b', 64),
            'satisfied',
            [[
                'command' => 'composer ci',
                'status' => 'passed',
                'exit_code' => 0,
                'executed_at' => '2026-08-21T09:00:00+00:00',
                'recorded_by' => 'fixture',
                'duration_ms' => 10,
            ]],
        );
        $sessionStore->setStatus($sessions[0], SessionStatus::DONE);

        $contracts->revise(
            self::TASK_ID,
            'Govern revision two.',
            ['docs/note.txt'],
            [],
            ['composer ci'],
            'fixture-planner',
        );
        $contracts->approve(self::TASK_ID, 'fixture-approver');

        $replacementEnter = $this->enter();
        self::assertSame(0, $replacementEnter['exit']);
        self::assertTrue($replacementEnter['payload']['mutation_ready'] ?? false);
        $replacementRun = $runs->find(self::TASK_ID);
        self::assertNotNull($replacementRun);
        self::assertNotSame($firstRun->runId, $replacementRun->runId);
        self::assertNull((new RunVerificationReceiptStore($this->root))->find(self::TASK_ID));

        $manifest = (new RunManifestProjector($this->root))->project(self::TASK_ID);
        self::assertNotContains(
            'verification.run_mismatch',
            array_column($manifest->disagreements, 'code'),
        );
        $archivedReceipts = glob(
            (new ProjectLayout($this->root))->runHistoryRoot(self::TASK_ID) . '/*/verification.json',
        ) ?: [];
        self::assertCount(1, $archivedReceipts);
    }

    private function createApprovedContract(string $goal): TaskContractStore
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            self::TASK_ID,
            $goal,
            ['docs/note.txt'],
            [],
            ['composer ci'],
            'fixture-planner',
        );
        $contracts->approve(self::TASK_ID, 'fixture-approver');

        return $contracts;
    }

    /** @return array{exit: int, payload: array<string, mixed>} */
    private function enter(): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand(
                $this->root,
                function (array $argv): int {
                    $this->writeRecallOutput();

                    return 0;
                },
            ))->run('enter', [self::TASK_ID, '--format=json']);
            $stdout = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        if (!is_string($stdout)) {
            throw new RuntimeException('Unable to capture enter JSON.');
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Enter JSON did not decode to an object.');
        }

        return ['exit' => $exit, 'payload' => $payload];
    }

    private function writeRecallOutput(): void
    {
        $contract = (new TaskContractStore($this->root))->load(self::TASK_ID);
        $directory = RecallOutputRoot::resolve($this->root) . '/' . self::TASK_ID;
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Recall fixture directory.');
        }

        $bundle = json_encode([
            'schema_version' => '1.0',
            'task' => [
                'id' => self::TASK_ID,
                'revision' => $contract->revision,
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents(
            $directory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => self::TASK_ID,
                'compilation_id' => self::TASK_ID . '-' . bin2hex(random_bytes(4)),
                'bundle_sha256' => hash('sha256', $bundle),
                'snapshot_sha256' => str_repeat('c', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
        file_put_contents($directory . '/recall.bundle.json', $bundle);
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
