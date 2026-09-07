<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowCli;

final class WorkflowPlanHelpTest extends TestCase
{
    public function testPlanHelpUsesWorkflowUsageInsteadOfParsingHelpAsTaskId(): void
    {
        $cli = new WorkflowCli(sys_get_temp_dir(), static fn (array $argv): int => 0);

        ob_start();
        $exit = $cli->run(['plan', '--help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop workflow plan <task-id>', $output);
        self::assertStringContainsString('--acceptance <text>', $output);
        self::assertStringContainsString('--acceptance-observation <json>', $output);
        self::assertStringContainsString('[--supersede]', $output);
    }
}
