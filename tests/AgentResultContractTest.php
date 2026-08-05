<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\AgentResultWriter;
use voku\AgentLoop\Edit\EditRequest;
use voku\AgentLoop\Edit\EditRunResult;
use voku\AgentLoop\Edit\VerificationPlanBinding;
use voku\AgentLoop\Edit\WorkingTreeSnapshot;
use voku\AgentLoop\Edit\WorkingTreeSnapshotter;

final class AgentResultContractTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-result-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/recall', 0o775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testBindingSeedsEmptySlotsFromThePlanIds(): void
    {
        $plan = $this->writePlan();
        $binding = VerificationPlanBinding::fromRecallDirectory($this->root . '/recall');

        self::assertSame('sha256:' . hash('sha256', $plan), $binding->planSha256);
        self::assertSame(
            ['probe:incoming-call:001' => [], 'probe:type-definition:002' => []],
            $binding->emptyProbeAnswers(),
        );
        self::assertSame(['check:contract' => []], $binding->emptyChecklistEvidence());
    }

    public function testBindingIsEmptyWhenNoPlanWasCompiled(): void
    {
        $binding = VerificationPlanBinding::fromRecallDirectory($this->root . '/recall');

        self::assertNull($binding->planSha256);
        self::assertSame([], $binding->emptyProbeAnswers());
        self::assertSame([], $binding->emptyChecklistEvidence());
    }

    public function testBindingSurvivesAnUnreadablePlanInsteadOfFailingTheEdit(): void
    {
        file_put_contents($this->root . '/recall/verification-plan.json', '{ not json');

        self::assertNull(VerificationPlanBinding::fromRecallDirectory($this->root . '/recall')->planSha256);
    }

    public function testResultRecordsObservedFactsAndNoVerdict(): void
    {
        $this->writePlan();
        $request = $this->request('EDIT-RESULT');

        $payload = (new AgentResultWriter())->write(
            $this->root,
            $request,
            new EditRunResult('runner_succeeded', 0, "applied\n"),
            VerificationPlanBinding::fromRecallDirectory($this->root . '/recall'),
            new WorkingTreeSnapshot(true, 'abc', ['src/UserService.php' => ' M:old']),
            new WorkingTreeSnapshot(true, 'abc', ['src/UserService.php' => ' M:new', 'src/New.php' => '??:hash']),
            [['id' => 'runner:mechanical', 'exit_code' => 0, 'stdout_sha256' => 'sha256:' . str_repeat('a', 64)]],
        );

        self::assertSame(['src/New.php', 'src/UserService.php'], $payload['changed_files']);
        self::assertSame('git_status_diff', $payload['changed_files_source']);
        self::assertSame('EDIT-RESULT', $payload['task_id']);
        self::assertSame(['probe:incoming-call:001' => [], 'probe:type-definition:002' => []], $payload['probe_answers']);

        $written = file_get_contents($this->root . '/' . AgentResultWriter::FILE_NAME);
        self::assertIsString($written);
        $decoded = json_decode($written, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertEqualsCanonicalizing(array_keys($payload), array_keys($decoded));

        // The answer sheet must never carry its own grade.
        foreach (['expected', 'score', 'verdict', 'passed', 'learning'] as $forbidden) {
            self::assertStringNotContainsStringIgnoringCase('"' . $forbidden, $written);
        }
    }

    public function testUnavailableSnapshotIsDistinguishableFromAnEditThatChangedNothing(): void
    {
        $request = $this->request('EDIT-NOGIT', dryRun: true);

        $payload = (new AgentResultWriter())->write(
            $this->root,
            $request,
            new EditRunResult('prepared'),
            VerificationPlanBinding::absent(),
            WorkingTreeSnapshot::unavailable(),
            WorkingTreeSnapshot::unavailable(),
            [],
        );

        self::assertSame([], $payload['changed_files']);
        self::assertSame('unavailable', $payload['changed_files_source']);
    }

    public function testSnapshotterReportsNoRepositoryInsteadOfThrowing(): void
    {
        $snapshot = (new WorkingTreeSnapshotter())->capture($this->root);

        self::assertFalse($snapshot->available);
        self::assertSame([], $snapshot->entries);
    }

    public function testSnapshotDiffIgnoresAFileRestoredToItsOriginalContent(): void
    {
        $before = new WorkingTreeSnapshot(true, 'abc', ['src/A.php' => ' M:same']);
        $after = new WorkingTreeSnapshot(true, 'abc', ['src/A.php' => ' M:same']);

        self::assertSame([], $after->changedPathsSince($before));
    }

    public function testSnapshotterSeesRealGitChangesIncludingUntrackedAndDeletedFiles(): void
    {
        $repository = $this->root . '/repo';
        mkdir($repository . '/src', 0o775, true);
        file_put_contents($repository . '/src/Kept.php', "<?php\n");
        file_put_contents($repository . '/src/Removed.php', "<?php\n");
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'config', 'user.email', 'test@example.com'],
            ['git', 'config', 'user.name', 'test'],
            ['git', 'add', '-A'],
            ['git', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 'baseline'],
        ] as $command) {
            $this->git($repository, $command);
        }

        $snapshotter = new WorkingTreeSnapshotter();
        $before = $snapshotter->capture($repository);
        self::assertTrue($before->available);
        self::assertSame([], $before->entries, 'a clean tree has no dirty entries to hash');

        file_put_contents($repository . '/src/Kept.php', "<?php\n// edited\n");
        file_put_contents($repository . '/src/Added.php', "<?php\n");
        unlink($repository . '/src/Removed.php');

        $changed = $snapshotter->capture($repository)->changedPathsSince($before);

        self::assertSame(['src/Added.php', 'src/Kept.php', 'src/Removed.php'], $changed);
    }

    /** @param non-empty-list<string> $command */
    private function git(string $workingDirectory, array $command): void
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), 'git ' . implode(' ', $command) . ' failed');
    }

    private function request(string $taskId, bool $dryRun = false): EditRequest
    {
        return new EditRequest(
            taskId: $taskId,
            target: 'Demo\\UserService::save',
            instruction: 'Reject inactive users before persistence.',
            projectRoot: $this->root,
            recallRoot: $this->root . '/recall',
            mapIndex: $this->root . '/.agent-map/php-symbols.json',
            mapRoot: $this->root,
            outputDirectory: $this->root,
            dryRun: $dryRun,
        );
    }

    private function writePlan(): string
    {
        $plan = json_encode([
            'schema_version' => '1.0',
            'knowledge_probes' => [
                ['id' => 'probe:type-definition:002', 'question' => 'q2'],
                ['id' => 'probe:incoming-call:001', 'question' => 'q1'],
            ],
            'checklist' => [['id' => 'check:contract', 'statement' => 's']],
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/recall/verification-plan.json', $plan);

        return $plan;
    }
}
