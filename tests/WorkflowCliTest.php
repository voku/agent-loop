<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowCli;

final class WorkflowCliTest extends TestCase
{
    public function testHelpExitsZero(): void
    {
        $result = $this->runCli(['help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop workflow start', $result['output']);
        self::assertStringContainsString('agent-loop workflow plan', $result['output']);
    }

    public function testLongHelpExitsZero(): void
    {
        $result = $this->runCli(['--help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop workflow close', $result['output']);
    }

    public function testShortHelpExitsZero(): void
    {
        $result = $this->runCli(['-h']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Commands:', $result['output']);
    }

    public function testUnknownCommandExitsOne(): void
    {
        $result = $this->runCli(['nope']);

        self::assertSame(1, $result['exit']);
    }

    public function testStartWithoutTaskIdExitsOne(): void
    {
        self::assertSame(1, $this->runCli(['start'])['exit']);
    }

    public function testStatusWithoutTaskIdExitsOne(): void
    {
        self::assertSame(1, $this->runCli(['status'])['exit']);
    }

    public function testCloseWithoutTaskIdExitsOne(): void
    {
        self::assertSame(1, $this->runCli(['close'])['exit']);
    }

    public function testPlanAndApproveWithoutTaskIdExitOne(): void
    {
        self::assertSame(1, $this->runCli(['plan'])['exit']);
        self::assertSame(1, $this->runCli(['approve'])['exit']);
        self::assertSame(1, $this->runCli(['context'])['exit']);
        self::assertSame(1, $this->runCli(['report'])['exit']);
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
     *
     * @return array{exit: int, output: string}
     */
    private function runCli(array $args): array
    {
        $cli = new WorkflowCli(
            sys_get_temp_dir(),
            static fn (array $argv): int => 0,
            static fn (array $argv): int => 0,
            static fn (array $argv): int => 0,
        );

        ob_start();
        $exit = $cli->run($args);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }
}
