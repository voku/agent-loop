<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCli;
use voku\AgentSession\SessionStore;

final class WorkflowCliTest extends TestCase
{
    public function testHelpExposesGovernedLifecycleWithoutStartShortcut(): void
    {
        $result = $this->runCli(['help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop workflow plan', $result['output']);
        self::assertStringContainsString('--acceptance <text>', $result['output']);
        self::assertStringContainsString('agent-loop workflow approve', $result['output']);
        self::assertStringContainsString('agent-loop workflow reflect', $result['output']);
        self::assertStringContainsString('agent-loop workflow handoff', $result['output']);
        self::assertStringContainsString('agent-loop workflow learn', $result['output']);
        self::assertStringContainsString('agent-loop workflow close', $result['output']);
        self::assertStringContainsString('checkpoint-autonomy', $result['output']);
        self::assertStringContainsString('momentum', $result['output']);
        self::assertStringNotContainsString('agent-loop workflow start', $result['output']);
        self::assertStringContainsString('session start --ephemeral', $result['output']);
        self::assertStringNotContainsString('--learning-root', $result['output']);
    }

    public function testHelpAliasesExitZero(): void
    {
        self::assertSame(0, $this->runCli(['--help'])['exit']);
        self::assertSame(0, $this->runCli(['-h'])['exit']);
    }

    public function testRemovedStartIsUnknownInsteadOfCompatibilityPath(): void
    {
        $result = $this->runCli(['start', 'ABC-123']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Usage:', $result['output']);
        self::assertStringNotContainsString('agent-loop workflow start', $result['output']);
    }

    public function testUnknownCommandExitsOne(): void
    {
        self::assertSame(1, $this->runCli(['nope'])['exit']);
    }

    public function testWorkflowCommandsRejectProjectLearningRootOverrides(): void
    {
        $commands = [
            ['plan', 'ABC-123', '--by', 'planner', '--learning-root', 'alternate'],
            ['approve', 'ABC-123', '--by', 'reviewer', '--learning-root', 'alternate'],
            ['context', 'ABC-123', '--learning-root', 'alternate'],
            ['report', 'ABC-123', '--learning-root', 'alternate'],
            ['learn', 'ABC-123', '--status', 'no_durable_learning', '--by', 'planner', '--reason', 'none', '--learning-root', 'alternate'],
            ['close', 'ABC-123', '--status', 'done', '--learning-root', 'alternate'],
            ['plan', 'ABC-123', '--by', 'planner', '--root', 'alternate'],
        ];

        foreach ($commands as $command) {
            self::assertSame(1, $this->runCli($command)['exit'], implode(' ', $command));
        }
    }

    public function testGovernedCommandsWithoutTaskIdFail(): void
    {
        foreach (['plan', 'approve', 'contract', 'status', 'manifest', 'context', 'report', 'reflect', 'handoff', 'learn', 'close'] as $command) {
            self::assertSame(1, $this->runCli([$command])['exit'], $command);
        }
    }

    public function testReportIsRoutedThroughWorkflowCli(): void
    {
        $result = $this->runCli(['report', 'ABC-123', '--format', 'json']);

        self::assertSame(0, $result['exit']);
        self::assertSame('ABC-123', json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR)['task_id']);
    }

    public function testHandoffIsRoutedThroughWorkflowCliWithSharedRecallRunner(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-workflow-cli-handoff-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0777, true));
        $layout = new ProjectLayout($root);
        $contractStore = new TaskContractStore($root);
        $contractStore->create('HANDOFF-1', 'Persist current handoff.', ['README.md'], [], ['composer test'], 'planner');
        $contract = $contractStore->approve('HANDOFF-1', 'reviewer');
        $sessionStore = new SessionStore();
        $session = $sessionStore->create($layout->sessionsRoot(), 'HANDOFF-1', by: 'agent');
        self::assertTrue(is_dir($layout->learningRoot()) || mkdir($layout->learningRoot(), 0777, true));
        (new GovernedRunStore($root))->prepare($contract, $session, $layout->learningRoot());

        $received = null;
        $cli = new WorkflowCli(
            $root,
            static function (array $argv) use (&$received): int {
                $received = $argv;

                return 0;
            },
        );

        ob_start();
        $exit = $cli->run(['handoff', 'HANDOFF-1', '--context', 'Verified current state; next agent should continue the existing card.']);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertIsArray($received);
        self::assertSame('compile', $received[0]);
        self::assertSame('HANDOFF-1', $received[2]);
        self::assertContains('{"id":"todo-card-handoff","arguments":{}}', $received);
    }

    public function testInvalidTaskIdExitsOne(): void
    {
        self::assertSame(1, $this->runCli(['status', '../bad'])['exit']);
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, output: string}
     */
    private function runCli(array $args): array
    {
        $cli = new WorkflowCli(sys_get_temp_dir(), static fn (array $argv): int => 0);

        ob_start();
        $exit = $cli->run($args);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }
}
