<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentEdit\Apply\WorkingTreeSnapshot;
use voku\AgentLoop\Edit\AgentResultWriter;
use voku\AgentLoop\Edit\EditRequest;
use voku\AgentLoop\Edit\EditRunResult;
use voku\AgentLoop\Edit\VerificationPlanBinding;
use voku\AgentLoop\Tests\Support\VerificationPlanFixture;

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
        self::assertNull($binding->error);
        self::assertSame([], $binding->emptyProbeAnswers());
        self::assertSame([], $binding->emptyChecklistEvidence());
    }

    public function testBindingSurvivesAnUnreadablePlanInsteadOfFailingTheEdit(): void
    {
        file_put_contents($this->root . '/recall/verification-plan.json', '{ not json');

        self::assertNull(VerificationPlanBinding::fromRecallDirectory($this->root . '/recall')->planSha256);
    }

    public function testAStructurallyDamagedPlanBindsNoPlanInsteadOfSeedingEmptySlots(): void
    {
        file_put_contents(
            $this->root . '/recall/verification-plan.json',
            (string) json_encode(VerificationPlanFixture::shape(['knowledge_probes' => 'kaputt']), JSON_THROW_ON_ERROR),
        );

        $binding = VerificationPlanBinding::fromRecallDirectory($this->root . '/recall');

        self::assertNull($binding->planSha256, 'without a plan hash, edit verify refuses this result');
        self::assertStringContainsString('knowledge_probes must be a JSON array', (string) $binding->error);
        self::assertSame([], $binding->emptyProbeAnswers());

        $payload = (new AgentResultWriter())->write(
            $this->root,
            $this->request('EDIT-RESULT'),
            new EditRunResult('prepared'),
            $binding,
            WorkingTreeSnapshot::unavailable(),
            WorkingTreeSnapshot::unavailable(),
            [],
            'method:Demo\\UserService::save',
        );
        self::assertSame($binding->error, $payload['verification_plan_error']);
    }

    public function testReaderDiagnosticsNeverPersistPlanValues(): void
    {
        $plan = VerificationPlanFixture::shape(['schema_version' => ['secret' => 'SENSITIVE_MARKER']]);
        file_put_contents($this->root . '/recall/verification-plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
        $binding = VerificationPlanBinding::fromRecallDirectory($this->root . '/recall');
        self::assertSame('Unsupported verification plan schema version', $binding->error);

        $payload = (new AgentResultWriter())->write(
            $this->root,
            $this->request('EDIT-RESULT'),
            new EditRunResult('prepared'),
            $binding,
            WorkingTreeSnapshot::unavailable(),
            WorkingTreeSnapshot::unavailable(),
            [],
            'method:Demo\\UserService::save',
        );
        self::assertSame($binding->error, $payload['verification_plan_error']);
        self::assertStringNotContainsString('SENSITIVE_MARKER', (string) file_get_contents($this->root . '/agent-result.json'));

        $plan = VerificationPlanFixture::shape(['knowledge_probes' => [['id' => 'SENSITIVE_MARKER'], ['id' => 'SENSITIVE_MARKER']]]);
        file_put_contents($this->root . '/recall/verification-plan.json', json_encode($plan, JSON_THROW_ON_ERROR));
        $binding = VerificationPlanBinding::fromRecallDirectory($this->root . '/recall');
        self::assertSame('Verification plan knowledge_probes has a duplicate id', $binding->error);
        self::assertStringNotContainsString('SENSITIVE_MARKER', (string) $binding->error);

        foreach ([
            [['map_digest' => ''], 'Verification plan map_digest must be a non-empty string'],
            [['generator' => ['name' => 'ok']], 'Verification plan generator.version must be a string'],
            [['required_evidence_types' => []], 'Verification plan required_evidence_types must be a non-empty JSON array'],
            [['knowledge_probes' => [['id' => '']]], 'Verification plan knowledge_probes contains an entry without a non-empty string id'],
        ] as [$overrides, $expected]) {
            file_put_contents($this->root . '/recall/verification-plan.json', json_encode(VerificationPlanFixture::shape($overrides), JSON_THROW_ON_ERROR));
            $error = VerificationPlanBinding::fromRecallDirectory($this->root . '/recall')->error;
            self::assertSame($expected, $error);
            self::assertStringNotContainsString($this->root, (string) $error);
        }
    }

    public function testInvalidJsonReportsSafeDistinctCauses(): void
    {
        foreach ([['{', 'syntax error'], ["{\"x\":\"\xFF\"}", 'malformed UTF-8']] as [$raw, $reason]) {
            file_put_contents($this->root . '/recall/verification-plan.json', $raw);
            $error = VerificationPlanBinding::fromRecallDirectory($this->root . '/recall')->error;
            self::assertSame('Invalid verification plan JSON: ' . $reason, $error);
            self::assertStringNotContainsString($this->root, (string) $error);
        }
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
            'method:Demo\\UserService::save',
        );

        self::assertSame(['src/New.php', 'src/UserService.php'], $payload['changed_files']);
        self::assertSame('git_status_diff', $payload['changed_files_source']);
        self::assertSame('EDIT-RESULT', $payload['task_id']);
        self::assertSame('method:Demo\\UserService::save', $payload['target'], 'the plan and key are keyed on the canonical id');
        self::assertSame('Demo\\UserService::save', $payload['requested_target']);
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
            'method:Demo\\UserService::save',
        );

        self::assertSame([], $payload['changed_files']);
        self::assertSame('unavailable', $payload['changed_files_source']);
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
        $plan = json_encode(VerificationPlanFixture::shape([
            'knowledge_probes' => [
                ['id' => 'probe:type-definition:002', 'question' => 'q2'],
                ['id' => 'probe:incoming-call:001', 'question' => 'q1'],
            ],
            'checklist' => [['id' => 'check:contract', 'statement' => 's']],
        ]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        file_put_contents($this->root . '/recall/verification-plan.json', $plan);

        return $plan;
    }
}
