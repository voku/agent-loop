<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunManifestStore;
use voku\AgentLoop\Run\RunManifestTransitionWriter;
use voku\AgentLoop\Run\RunRetirementStore;
use voku\AgentLoop\Run\RunVerificationReceiptStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCloseCommand;
use voku\AgentLoop\Workflow\WorkflowRetireCommand;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;
use voku\AgentSession\SessionStatus;
use voku\AgentSession\SessionStore;

/**
 * A Run that was overtaken or never finished must be able to end honestly: retired is terminal, says who
 * decided and why, is bound to that one Run, and is not a successful close.
 */
final class RunRetirementTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-retirement-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testRetiredRunProjectsAsTerminalWithNothingLeftToDo(): void
    {
        $run = $this->preparedRun();

        (new RunRetirementStore($this->root))->record($run, 'superseded', 'A later product decision replaced this work.', 'lars');
        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertSame('retired', $manifest->state);
        self::assertSame('none', $manifest->nextAction);
        self::assertSame('superseded', $manifest->references['retirement']['status']);
        self::assertSame('lars', $manifest->references['retirement']['retired_by']);
        self::assertSame('A later product decision replaced this work.', $manifest->references['retirement']['reason']);
    }

    public function testRunWithoutRetirementKeepsItsReferencesUntouched(): void
    {
        $this->preparedRun();

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertNotSame('retired', $manifest->state);
        self::assertArrayNotHasKey('retirement', $manifest->references);
    }

    public function testRetirementIsBoundToOneRunAndNotInheritedBySuccessor(): void
    {
        $run = $this->preparedRun();
        (new RunRetirementStore($this->root))->record($run, 'abandoned', 'Never finished.', 'lars');
        $sessions = new SessionStore();
        $sessions->setStatus($sessions->load($this->root . '/.agent-loop/sessions', $run->sessionId), SessionStatus::DONE);
        $contracts = new TaskContractStore($this->root);
        $revised = $contracts->approve('ABC-123', 'lars', $contracts->revise('ABC-123', 'Second attempt.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars')->revision);

        $beforeSuccessor = (new RunManifestProjector($this->root))->project('ABC-123');
        self::assertSame('incomplete', $beforeSuccessor->state, 'Approved successor must be preparable through enter.');
        self::assertSame('retired', $beforeSuccessor->references['retirement']['state'], 'Previous Run remains retired.');

        (new GovernedRunStore($this->root))->prepare(
            $revised,
            $sessions->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars'),
            $this->root . '/.agent-loop/learning',
        );

        $manifest = (new RunManifestProjector($this->root))->project('ABC-123');

        self::assertNotSame('retired', $manifest->state);
    }

    public function testRetirementRejectsCloseAndExecutionWithoutWritingCompletionEvidence(): void
    {
        $run = $this->preparedRun();
        (new RunRetirementStore($this->root))->record($run, 'abandoned', 'No acceptable outcome.', 'lars');

        ob_start();
        try {
            $closeStatus = (new WorkflowCloseCommand($this->root))->run([
                'ABC-123', '--status', 'done', '--accept-risk', 'Legacy work.',
                '--accept-risk-by', 'lars', '--format=json',
            ]);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $closeStatus, $output);
        self::assertStringContainsString('retired', $output);
        self::assertNull((new RunVerificationReceiptStore($this->root))->find('ABC-123'));
        $session = (new SessionStore())->load($this->root . '/.agent-loop/sessions', $run->sessionId);
        self::assertFalse($session->status->isClosed());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('retired');
        (new ExecutionGateway($this->root))->prepareStage('ABC-123', 'build');
    }

    public function testRetirementNeedsAReasonAndAnActor(): void
    {
        $run = $this->preparedRun();

        $this->expectException(RuntimeException::class);

        (new RunRetirementStore($this->root))->record($run, 'abandoned', '  ', 'lars');
    }

    public function testRunWithFinalVerificationEvidenceCannotBeRetired(): void
    {
        $run = $this->preparedRun();
        (new RunVerificationReceiptStore($this->root))->record(
            $run,
            (new TaskContractStore($this->root))->load('ABC-123'),
            (new SessionStore())->load($this->root . '/.agent-loop/sessions', $run->sessionId),
            'sha256:' . str_repeat('a', 64),
            'satisfied',
            [['command' => 'vendor/bin/phpunit', 'status' => 'passed', 'exit_code' => 0, 'executed_at' => '2026-10-28T00:00:00+00:00', 'recorded_by' => 'lars', 'duration_ms' => 1]],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('final verification evidence');

        (new RunRetirementStore($this->root))->record($run, 'abandoned', 'Gave up.', 'lars');
    }

    public function testOnlyAbandonedAndSupersededAreAccepted(): void
    {
        $run = $this->preparedRun();

        $this->expectException(RuntimeException::class);

        (new RunRetirementStore($this->root))->record($run, 'done', 'No.', 'lars');
    }

    public function testCommandRetiresAndRefusesTheSameRunTwice(): void
    {
        $this->preparedRun();
        $command = new WorkflowRetireCommand($this->root);
        $args = ['ABC-123', '--status', 'abandoned', '--reason', 'Obsolete.', '--by', 'lars'];

        ob_start();
        $first = $command->run($args);
        ob_end_clean();
        $second = @$command->run($args);

        self::assertSame(0, $first);
        self::assertSame(1, $second);
    }

    public function testCommandRefreshesExistingManifestAndTextStatusExplainsRetirement(): void
    {
        $this->preparedRun();
        $manifestStore = new RunManifestStore($this->root);
        (new RunManifestTransitionWriter($this->root))->writeRecoveryProjection('ABC-123');
        self::assertNotSame('retired', $manifestStore->read('ABC-123')['state'] ?? null);

        ob_start();
        try {
            $retireExit = (new WorkflowRetireCommand($this->root))->run([
                'ABC-123', '--status', 'abandoned', '--reason', 'No longer needed.', '--by', 'lars',
            ]);
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $retireExit);
        self::assertSame('retired', $manifestStore->read('ABC-123')['state'] ?? null);
        $projected = (new RunManifestProjector($this->root))->project('ABC-123');
        self::assertSame('current', $manifestStore->status($projected)['state']);

        ob_start();
        try {
            $statusExit = (new WorkflowStatusCommand($this->root))->run(['ABC-123']);
            $text = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $statusExit);
        self::assertStringContainsString('Manifest:', $text);
        self::assertStringContainsString('Retirement:', $text);
        self::assertStringContainsString('abandoned by "lars"', $text);
        self::assertStringContainsString('No longer needed.', $text);
    }

    public function testCommandRefusesATaskWithoutAGovernedRun(): void
    {
        self::assertSame(1, @(new WorkflowRetireCommand($this->root))->run(['NOPE-1', '--status', 'abandoned', '--reason', 'x', '--by', 'lars']));
    }

    private function preparedRun(): GovernedRun
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create('ABC-123', 'Prove retirement.', ['src/Foo.php'], [], ['vendor/bin/phpunit'], 'lars');
        $contract = $contracts->approve('ABC-123', 'lars', $contracts->load('ABC-123')->revision);
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'ABC-123', by: 'lars');

        return (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');
    }
}
