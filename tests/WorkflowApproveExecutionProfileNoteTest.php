<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProfileSelectionStore;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentSession\SessionStore;

final class WorkflowApproveExecutionProfileNoteTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-approve-note-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/docs', 0o775, true) && !is_dir($this->root . '/docs')) {
            throw new RuntimeException('Unable to create fixture.');
        }
        file_put_contents($this->root . '/docs/note.txt', "current\n");
        (new TaskContractStore($this->root))->create(
            'NOTE-1',
            'Approve one bounded text change.',
            ['docs/note.txt'],
            [],
            ['php -r "exit(0);"'],
            'planner',
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /** @param list<string> $args */
    private function approve(array $args): string
    {
        $taskId = (string) ($args[0] ?? '');
        $revision = (new TaskContractStore($this->root))->load($taskId)->revision;
        array_splice($args, 1, 0, ['--revision', (string) $revision]);

        ob_start();
        try {
            $exit = (new WorkflowApproveCommand($this->root))->run($args);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit, $output);

        return $output;
    }

    public function testTextNoteBeforeNextNamesProfileAndCommand(): void
    {
        $output = $this->approve(['NOTE-1', '--by', 'approver']);

        $note = strpos($output, '[NOTE]');
        self::assertNotFalse($note);
        self::assertLessThan(strpos($output, '[NEXT]'), $note);
        self::assertSame(1, substr_count($output, '[NOTE]'));
        self::assertStringContainsString('standard', $output);
        self::assertStringContainsString('fixed by the first enter', $output);
        self::assertStringContainsString('workflow execution-profile NOTE-1 --profile <manual|surgical|standard|hardened> --by <actor>', $output);
    }

    public function testJsonCarriesNotesAndKeepsNextAction(): void
    {
        $data = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--format=json']), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('1.0', $data['schema_version']);
        self::assertSame('command', $data['next_action_kind']);
        self::assertStringContainsString('enter NOTE-1 --format=json', $data['next_action']);
        self::assertCount(1, $data['notes']);
        self::assertStringContainsString('fixed by the first enter', $data['notes'][0]);
        self::assertSame('standard', $data['execution_profile_choice']['current']);
        self::assertSame('vendor/bin/agent-loop workflow execution-profile NOTE-1 --profile <manual|surgical|standard|hardened> --by <actor>', $data['execution_profile_choice']['command']);
    }

    public function testNoNoteWhenAlreadyApproved(): void
    {
        $this->approve(['NOTE-1', '--by', 'approver']);
        $output = $this->approve(['NOTE-1', '--by', 'approver']);
        self::assertStringNotContainsString('[NOTE]', $output);

        $data = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('notes', $data);
        self::assertSame('standard', $data['execution_profile_choice']['current']);
    }

    public function testRetryReflectsSelectedProfileUntilRunExists(): void
    {
        $this->approve(['NOTE-1', '--by', 'approver']);
        (new ExecutionProfileSelectionStore($this->root))->select('NOTE-1', ExecutionProfileName::SURGICAL, 'approver');

        $before = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('already_approved', $before['status']);
        self::assertSame('surgical', $before['execution_profile_choice']['current']);

        $contract = (new TaskContractStore($this->root))->load('NOTE-1');
        $session = (new SessionStore())->create($this->root . '/.agent-loop/sessions', 'NOTE-1', by: 'fixture-agent');
        (new GovernedRunStore($this->root))->prepare($contract, $session, $this->root . '/.agent-loop/learning');

        $after = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('already_approved', $after['status']);
        self::assertArrayNotHasKey('execution_profile_choice', $after);
    }

    public function testRetryReportsUnreadableProfileProjectionWithoutChangingApproval(): void
    {
        $this->approve(['NOTE-1', '--by', 'approver']);
        $path = (new ExecutionProfileSelectionStore($this->root))->path('NOTE-1');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, '{ invalid');

        $data = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('already_approved', $data['status']);
        self::assertArrayNotHasKey('execution_profile_choice', $data);
        self::assertStringContainsString('Invalid execution profile selection JSON', $data['execution_profile_choice_error']);
    }

    public function testRetryReportsUnsupportedRunWithoutChangingApproval(): void
    {
        $this->approve(['NOTE-1', '--by', 'approver']);
        $path = (new GovernedRunStore($this->root))->path('NOTE-1');
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0o775, true);
        }
        file_put_contents($path, '{"schema_version":"unsupported"}');

        $data = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('already_approved', $data['status']);
        self::assertArrayNotHasKey('execution_profile_choice', $data);
        self::assertNotEmpty($data['execution_profile_choice_error']);
    }

    public function testLegacyContractApprovalAndRetryOfferStandardProfile(): void
    {
        $path = (new TaskContractStore($this->root))->load('NOTE-1')->path;
        $legacy = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        unset($legacy['acceptance_criteria'], $legacy['acceptance_observations']);
        file_put_contents($path, json_encode($legacy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $approved = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('approved', $approved['status']);
        self::assertSame('standard', $approved['execution_profile_choice']['current']);

        $retry = json_decode($this->approve(['NOTE-1', '--by', 'approver', '--json']), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('already_approved', $retry['status']);
        self::assertSame('standard', $retry['execution_profile_choice']['current']);
    }
}
