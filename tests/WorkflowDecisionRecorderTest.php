<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Workflow\HostFrontDoorApplication;
use voku\AgentLoop\Workflow\ReviewAcknowledgementStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentSession\SessionStore;

/**
 * Who decided (approved_by / rejected_by / acknowledged_by) and who entered the
 * decision (recorded_by) are separate statements; authority_evidence only ever
 * says the attribution is self_asserted, never verified.
 */
final class WorkflowDecisionRecorderTest extends TestCase
{
    private string $root;
    private string $head;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-decision-recorder-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nreturn 'current';\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'x@y.test');
        $this->git('config', 'user.name', 'X');
        $this->git('add', '.');
        $this->git('commit', '-qm', 'base');
        $this->head = $this->git('rev-parse', 'HEAD');
        [$exit, $output] = $this->cli(['plan', 'T-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', 'Change Foo.', '--validation', 'php -r "exit(0);"']);
        self::assertSame(0, $exit, $output);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testApprovalByARecorderStoresBothStatementsAndTheStatusSaysNotVerified(): void
    {
        [$exit, $output] = $this->cli(['approve', 'T-1', '--revision', '1', '--by', 'Lars Moelleken', '--recorded-by', 'claude', '--head', $this->head]);
        self::assertSame(0, $exit, $output);

        $data = $this->json($this->contract()->path);
        self::assertSame('Lars Moelleken', $data['approved_by']);
        self::assertSame('claude', $data['recorded_by']);
        self::assertSame(['status' => 'self_asserted'], $data['authority_evidence']);
        self::assertSame('claude', $this->contract()->recordedBy);

        $approval = (new RunManifestProjector($this->root))->project('T-1')->references['approval'];
        self::assertSame('Lars Moelleken', $approval['approved_by']);
        self::assertSame('claude', $approval['recorded_by']);
        self::assertSame(['status' => 'self_asserted'], $approval['authority_evidence']);

        [$exit, $status] = $this->cli(['status', 'T-1']);
        self::assertStringContainsString('by Lars Moelleken, recorded by claude (self-asserted, not verified)', $status, (string) $exit);
        self::assertStringNotContainsStringIgnoringCase('authenticated', $status);
    }

    public function testApprovalWithoutARecorderIsByteIdenticalToTheEarlierFormat(): void
    {
        [$exit, $output] = $this->cli(['approve', 'T-1', '--revision', '1', '--by', 'Lars Moelleken']);
        self::assertSame(0, $exit, $output);

        $contract = $this->contract();
        $written = $this->read($contract->path);
        $data = json_decode($written, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('recorded_by', $data);
        self::assertArrayNotHasKey('authority_evidence', $data);
        self::assertNull($contract->recordedBy);
        self::assertSame($written, CanonicalJson::pretty($contract->toArray()));

        $approval = (new RunManifestProjector($this->root))->project('T-1')->references['approval'];
        self::assertArrayNotHasKey('recorded_by', $approval);
        self::assertArrayNotHasKey('authority_evidence', $approval);
        [, $status] = $this->cli(['status', 'T-1']);
        self::assertStringNotContainsString('recorded by', $status);
    }

    public function testRejectAndRequestChangesStoreTheRecorder(): void
    {
        [$exit, $output] = $this->cli(['request-changes', 'T-1', '--revision', '1', '--by', 'Lars Moelleken', '--reason', 'Cover the archive.', '--recorded-by', 'claude']);
        self::assertSame(0, $exit, $output);
        $changeRequest = $this->json($this->contract()->path);
        self::assertSame(TaskContract::CHANGE_REQUEST, $changeRequest['rejection_kind']);
        self::assertSame('claude', $changeRequest['recorded_by']);
        self::assertSame(['status' => 'self_asserted'], $changeRequest['authority_evidence']);

        $this->planRevision('Change Foo again.');
        [$exit, $output] = $this->cli(['reject', 'T-1', '--revision', '2', '--by', 'Lars Moelleken', '--reason', 'No.', '--recorded-by', 'claude']);
        self::assertSame(0, $exit, $output);
        $rejection = $this->json($this->contract()->path);
        self::assertSame(TaskContract::REJECTION, $rejection['rejection_kind']);
        self::assertSame('claude', $rejection['recorded_by']);
    }

    public function testAnArchivedRevisionKeepsTheRecorderAndANewCandidateCarriesNone(): void
    {
        [$exit] = $this->cli(['approve', 'T-1', '--revision', '1', '--by', 'Lars Moelleken', '--recorded-by', 'claude']);
        self::assertSame(0, $exit);
        $approved = $this->read($this->contract()->path);

        $this->planRevision('Change Foo with a second revision.', ['--supersede']);

        $archived = $this->json($this->root . '/.agent-loop/contracts/T-1/history/contract.001.json');
        self::assertSame('Lars Moelleken', $archived['approved_by']);
        self::assertSame('claude', $archived['recorded_by']);
        self::assertSame(['status' => 'self_asserted'], $archived['authority_evidence']);
        self::assertSame(json_decode($approved, true)['approved_at'], $archived['approved_at']);

        $candidate = $this->json($this->contract()->path);
        self::assertSame(TaskContract::CANDIDATE, $candidate['status']);
        self::assertArrayNotHasKey('recorded_by', $candidate);
        self::assertArrayNotHasKey('authority_evidence', $candidate);
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function refusedRecorders(): iterable
    {
        yield 'approve blank' => ['approve', ['--revision', '1', '--by', 'Lars', '--recorded-by', ' ']];
        yield 'approve reserved' => ['approve', ['--revision', '1', '--by', 'Lars', '--recorded-by', 'standing:policy']];
        yield 'approve missing value' => ['approve', ['--revision', '1', '--by', 'Lars', '--recorded-by']];
        yield 'reject reserved' => ['reject', ['--revision', '1', '--by', 'Lars', '--reason', 'r', '--recorded-by', 'standing:policy']];
        yield 'request-changes blank' => ['request-changes', ['--revision', '1', '--by', 'Lars', '--reason', 'r', '--recorded-by', ' ']];
    }

    /** @param list<string> $arguments */
    #[DataProvider('refusedRecorders')]
    public function testAnInvalidRecorderIsRefusedWithoutWriting(string $command, array $arguments): void
    {
        $before = $this->read($this->contract()->path);

        [$exit] = $this->cli([$command, 'T-1', ...$arguments]);

        self::assertSame(1, $exit);
        self::assertSame($before, $this->read($this->contract()->path));
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function tamperedRecords(): iterable
    {
        yield 'verified status' => [['recorded_by' => 'claude', 'authority_evidence' => ['status' => 'verified']]];
        yield 'unknown status' => [['recorded_by' => 'claude', 'authority_evidence' => ['status' => 'trusted']]];
        yield 'evidence with extra keys' => [['recorded_by' => 'claude', 'authority_evidence' => ['status' => 'self_asserted', 'certificate' => 'x']]];
        yield 'evidence not an object' => [['recorded_by' => 'claude', 'authority_evidence' => 'self_asserted']];
        yield 'status without recorder' => [['authority_evidence' => ['status' => 'self_asserted']]];
        yield 'recorder without status' => [['recorded_by' => 'claude']];
        yield 'recorder not a string' => [['recorded_by' => 7, 'authority_evidence' => ['status' => 'self_asserted']]];
        yield 'recorder blank' => [['recorded_by' => '', 'authority_evidence' => ['status' => 'self_asserted']]];
        yield 'recorder padded' => [['recorded_by' => ' claude', 'authority_evidence' => ['status' => 'self_asserted']]];
        yield 'recorder reserved' => [['recorded_by' => 'standing:policy', 'authority_evidence' => ['status' => 'self_asserted']]];
    }

    /** @param array<string, mixed> $fields */
    #[DataProvider('tamperedRecords')]
    public function testATamperedContractRecordFailsClosedOnLoad(array $fields): void
    {
        [$exit] = $this->cli(['approve', 'T-1', '--revision', '1', '--by', 'Lars']);
        self::assertSame(0, $exit);
        $path = $this->contract()->path;
        file_put_contents($path, json_encode([...$this->json($path), ...$fields], JSON_THROW_ON_ERROR));

        $this->expectException(RuntimeException::class);
        $this->contract();
    }

    public function testACandidateNamingARecorderFailsClosedOnLoad(): void
    {
        $path = $this->contract()->path;
        file_put_contents($path, json_encode([
            ...$this->json($path),
            'recorded_by' => 'claude',
            'authority_evidence' => ['status' => 'self_asserted'],
        ], JSON_THROW_ON_ERROR));

        $this->expectExceptionMessage('recorder without a recorded decision');
        $this->contract();
    }

    public function testReviewAcknowledgementStoresTheRecorderOnlyWhenGivenAndFailsClosedWhenTampered(): void
    {
        [$run, $contract, $snapshot, $reportSha256] = $this->preparedReview();
        $store = new ReviewAcknowledgementStore($this->root);

        $plain = $store->record($run, $contract, $snapshot, $reportSha256, 'Lars Moelleken');
        $written = $this->read($store->path('T-1'));
        self::assertArrayNotHasKey('recorded_by', json_decode($written, true));
        self::assertSame($written, CanonicalJson::pretty($plain->toArray()));

        try {
            $store->record($run, $contract, $snapshot, $reportSha256, 'Lars Moelleken', 'claude');
            self::fail('A different recorder must not silently replace the existing acknowledgement.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('different recorder', $exception->getMessage());
        }
        self::assertSame($written, $this->read($store->path('T-1')));

        unlink($store->path('T-1'));
        $recorded = $store->record($run, $contract, $snapshot, $reportSha256, 'Lars Moelleken', 'claude');
        self::assertSame('claude', $recorded->recordedBy);
        $data = $this->json($store->path('T-1'));
        self::assertSame('Lars Moelleken', $data['acknowledged_by']);
        self::assertSame('claude', $data['recorded_by']);
        self::assertSame(['status' => 'self_asserted'], $data['authority_evidence']);
        self::assertSame('claude', $store->find('T-1')?->recordedBy);

        $data['authority_evidence'] = ['status' => 'verified'];
        file_put_contents($store->path('T-1'), json_encode($data, JSON_THROW_ON_ERROR));
        $this->expectException(RuntimeException::class);
        $store->find('T-1');
    }

    public function testFinishAcceptsARecorderOnlyWithTheReviewAcknowledgement(): void
    {
        [, , , $reportSha256] = $this->preparedReview(viaFinish: true);

        $refused = $this->finishJson(['--recorded-by', 'claude']);
        self::assertSame(1, $refused['exit']);
        self::assertStringContainsString('--recorded-by requires --reviewed-report-sha256', (string) json_encode($refused['payload']));

        $result = $this->finishJson(['--reviewed-report-sha256', $reportSha256, '--by', 'Lars Moelleken', '--recorded-by', 'claude']);
        self::assertNotNull((new ReviewAcknowledgementStore($this->root))->find('T-1'), json_encode($result['payload'], JSON_THROW_ON_ERROR));
        $data = $this->json((new ReviewAcknowledgementStore($this->root))->path('T-1'));
        self::assertSame('Lars Moelleken', $data['acknowledged_by']);
        self::assertSame('claude', $data['recorded_by']);
    }

    public function testQuickStoresTheRecorderNextToTheActor(): void
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorApplication($this->root))->run('quick', [
                'QUICK-1',
                'Edit Foo',
                '--file=src/Foo.php',
                '--actor=Lars Moelleken',
                '--recorded-by=claude',
                '--format=json',
            ]);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $contract = (new TaskContractStore($this->root))->load('QUICK-1');
        self::assertSame('Lars Moelleken', $contract->approvedBy, $stdout . ' exit ' . $exit);
        self::assertSame('claude', $contract->recordedBy);
        self::assertSame(['status' => 'self_asserted'], $this->json($contract->path)['authority_evidence']);
    }

    /**
     * @return array{0: \voku\AgentLoop\Run\GovernedRun, 1: TaskContract, 2: \voku\AgentLoop\Workflow\ImplementationSnapshot, 3: string}
     */
    private function preparedReview(bool $viaFinish = false): array
    {
        $contracts = new TaskContractStore($this->root);
        $contract = $contracts->approve('T-1', 'Lars Moelleken', 1);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'T-1', by: 'fixture-agent');
        $run = (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');
        $recall = $this->root . '/.agent-loop/recall/T-1';
        mkdir($recall, 0o775, true);
        file_put_contents($recall . '/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => 'T-1',
            'compilation_id' => 't-1-fixture',
            'bundle_sha256' => str_repeat('a', 64),
            'selected_guidance' => [],
            'selected_constraints' => [],
            'output_hashes' => [],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($recall . '/validation-plan.md', "# Validation\n\nRun the approved command.\n");
        $snapshot = \voku\AgentLoop\Workflow\ImplementationSnapshot::capture($this->root, $contract);
        if ($viaFinish) {
            self::assertSame(1, $this->finishJson()['exit']);
        } else {
            mkdir($recall . '/reviews', 0o775, true);
            file_put_contents($recall . '/reviews/T-1.blindspots.json', json_encode([
                'version' => 2,
                'task_id' => 'T-1',
                'status' => 'ok',
                'contract_revision' => 1,
                'implementation_snapshot' => $snapshot->digest,
                'findings' => [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
        }
        $review = (new WorkflowReviewReportReader($this->root))->read('T-1');
        self::assertIsString($review['sha256']);

        return [$run, $contract, $snapshot, $review['sha256']];
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<mixed>}
     */
    private function finishJson(array $options = []): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorApplication($this->root))->run('finish', ['T-1', '--format=json', ...$options]);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return ['exit' => $exit, 'payload' => $payload];
    }

    /** @param list<string> $extra */
    private function planRevision(string $goal, array $extra = []): void
    {
        [$exit, $output] = $this->cli(['plan', 'T-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', $goal, '--validation', 'php -r "exit(0);"', ...$extra]);
        self::assertSame(0, $exit, $output);
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $data = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function contract(): TaskContract
    {
        return (new TaskContractStore($this->root))->load('T-1');
    }

    /**
     * @param list<string> $arguments
     * @return array{0: int, 1: string}
     */
    private function cli(array $arguments): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = (new WorkflowCli($this->root))->run($arguments);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exit, $output];
    }

    private function git(string ...$arguments): string
    {
        exec('env -u GIT_DIR -u GIT_WORK_TREE -u GIT_COMMON_DIR -u GIT_INDEX_FILE -u GIT_OBJECT_DIRECTORY -u GIT_ALTERNATE_OBJECT_DIRECTORIES git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
