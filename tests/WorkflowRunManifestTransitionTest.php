<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLearning\RunLearningDecisionStatus;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\PostExecutionEvidenceBoundary;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;

final class WorkflowRunManifestTransitionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-transition-manifest-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testPlanPersistsContractWithoutCreatingRunProjection(): void
    {
        ob_start();
        $exit = (new WorkflowPlanCommand($this->root))->run([
            'ABC-123',
            '--by', 'lars',
            '--file', 'src/Foo.php',
            '--goal', 'Keep the task scope reviewable.',
            '--validation', 'vendor/bin/phpunit',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('candidate Contract created', $output);
        self::assertNull((new RunManifestStore($this->root))->read('ABC-123'));
        self::assertNull((new GovernedRunStore($this->root))->find('ABC-123'));
        self::assertSame([], (new SessionStore())->all($this->root . '/.agent-loop/sessions'));
    }

    public function testEnterCanResumeCompilationAfterContractWasAlreadyApproved(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        $contracts->approve('ABC-123', 'lars');
        $recallCalls = 0;

        $command = new HostFrontDoorCommand(
            $this->root,
            function (array $argv) use (&$recallCalls): int {
                ++$recallCalls;
                $this->writeRecallMeta();

                return 0;
            },
        );

        ob_start();
        $exit = $command->run('enter', ['ABC-123', '--format=json']);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertSame(1, $recallCalls);

        $sessions = (new SessionStore())->all($this->root . '/.agent-loop/sessions');
        self::assertCount(1, $sessions);
        $run = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($run);
        $manifest = $this->manifest();
        self::assertSame($run->runId, $this->stringField($manifest, 'run_id'));
        self::assertSame('current', $this->referenceState($manifest, 'approval'));
        self::assertSame('compiled', $this->referenceState($manifest, 'recall'));
    }

    public function testFailedCompilationLeavesApprovedContractAndPreparedRunResumable(): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');

        ob_start();
        $approveExit = (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--by', 'lars']);
        ob_end_clean();

        self::assertSame(0, $approveExit);
        self::assertSame('approved', $contracts->load('ABC-123')->status);
        self::assertNull((new GovernedRunStore($this->root))->find('ABC-123'));
        self::assertSame([], (new SessionStore())->all($this->root . '/.agent-loop/sessions'));

        ob_start();
        $firstExit = (new HostFrontDoorCommand(
            $this->root,
            static fn (array $argv): int => 7,
        ))->run('enter', ['ABC-123', '--format=json']);
        ob_end_clean();

        self::assertSame(1, $firstExit);
        $firstRun = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($firstRun);
        self::assertCount(1, (new SessionStore())->all($this->root . '/.agent-loop/sessions'));
        self::assertSame('missing', $this->referenceState($this->manifest(), 'recall'));

        $second = new HostFrontDoorCommand(
            $this->root,
            function (array $argv): int {
                $this->writeRecallMeta();

                return 0;
            },
        );
        ob_start();
        $secondExit = $second->run('enter', ['ABC-123', '--format=json']);
        ob_end_clean();

        self::assertSame(0, $secondExit);
        self::assertSame($firstRun->runId, (new GovernedRunStore($this->root))->find('ABC-123')?->runId);
        self::assertCount(1, (new SessionStore())->all($this->root . '/.agent-loop/sessions'));
        self::assertSame('compiled', $this->referenceState($this->manifest(), 'recall'));
    }

    public function testSuccessfulCliClosePersistsFinalDurableProjection(): void
    {
        $session = $this->prepareApprovedRun();
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");

        $contract = (new TaskContractStore($this->root))->load('ABC-123');
        $run = (new GovernedRunStore($this->root))->find('ABC-123');
        self::assertNotNull($run);
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

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
        mkdir($this->root . '/.agent-loop/recall/ABC-123/reviews', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/reviews/ABC-123.blindspots.json',
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
            'No durable learning from manifest transition fixture.',
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
            validationEvidenceSha256: $validationSha256,
            reviewEvidenceSha256: $reviewSha256,
        );

        $cli = new WorkflowCli($this->root, static fn (array $argv): int => 0);
        ob_start();
        $exit = $cli->run(['close', 'ABC-123', '--status', 'done']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString('final Run projection is complete', $output);
        self::assertFileExists($this->root . '/.agent-loop/runs/ABC-123/verification.json');
        $manifest = $this->manifest();
        self::assertSame($run->runId, $this->stringField($manifest, 'run_id'));
        self::assertSame('complete', $this->stringField($manifest, 'state'));
        self::assertSame('done', $this->referenceState($manifest, 'session'));
        self::assertSame('passed', $this->referenceState($manifest, 'verification'));
        self::assertSame('decided', $this->referenceState($manifest, 'learning'));
        self::assertSame('none', $this->stringField($manifest, 'next_action'));
    }

    private function prepareApprovedRun(): Session
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');

        ob_start();
        self::assertSame(0, (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--by', 'lars']));
        self::assertSame(0, (new HostFrontDoorCommand(
            $this->root,
            function (array $argv): int {
                $this->writeRecallMeta();

                return 0;
            },
        ))->run('enter', ['ABC-123', '--format=json']));
        ob_end_clean();

        $sessions = (new SessionStore())->all($this->root . '/.agent-loop/sessions');
        self::assertCount(1, $sessions);

        return $sessions[0];
    }

    private function writeRecallMeta(): void
    {
        if (!is_dir($this->root . '/.agent-loop/recall/ABC-123')) {
            mkdir($this->root . '/.agent-loop/recall/ABC-123', 0o775, true);
        }
        file_put_contents(
            $this->root . '/.agent-loop/recall/ABC-123/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-001',
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $manifest = (new RunManifestStore($this->root))->read('ABC-123');
        self::assertNotNull($manifest);

        return $manifest;
    }

    /** @param array<string, mixed> $manifest */
    private function stringField(array $manifest, string $key): string
    {
        $value = $manifest[$key] ?? null;
        self::assertIsString($value);

        return $value;
    }

    /** @param array<string, mixed> $manifest */
    private function referenceState(array $manifest, string $referenceName): string
    {
        $references = $manifest['references'] ?? null;
        self::assertIsArray($references);
        $reference = $references[$referenceName] ?? null;
        self::assertIsArray($reference);
        $state = $reference['state'] ?? null;
        self::assertIsString($state);

        return $state;
    }

    private function rm(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
