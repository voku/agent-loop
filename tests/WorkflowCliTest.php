<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowCli;

final class WorkflowCliTest extends TestCase
{
    public function testHelpExposesGovernedLifecycleWithoutStartShortcut(): void
    {
        $result = $this->runCli(['help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop workflow plan', $result['output']);
        self::assertStringContainsString('agent-loop workflow approve', $result['output']);
        self::assertStringContainsString('agent-loop workflow reflect', $result['output']);
        self::assertStringContainsString('agent-loop workflow learn', $result['output']);
        self::assertStringContainsString('agent-loop workflow close', $result['output']);
        self::assertStringContainsString('checkpoint-autonomy', $result['output']);
        self::assertStringContainsString('momentum', $result['output']);
        self::assertStringNotContainsString('agent-loop workflow start', $result['output']);
        self::assertStringContainsString('session start --ephemeral', $result['output']);
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

    public function testGovernedCommandsWithoutTaskIdFail(): void
    {
        foreach (['plan', 'approve', 'contract', 'status', 'manifest', 'context', 'report', 'reflect', 'learn', 'close'] as $command) {
            self::assertSame(1, $this->runCli([$command])['exit'], $command);
        }
    }

    public function testReportIsRoutedThroughWorkflowCli(): void
    {
        $result = $this->runCli(['report', 'ABC-123', '--format', 'json']);

        self::assertSame(0, $result['exit']);
        self::assertSame('ABC-123', json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR)['task_id']);
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
