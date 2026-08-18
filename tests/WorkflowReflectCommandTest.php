<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowReflectCommand;

final class WorkflowReflectCommandTest extends TestCase
{
    public function testProjectReflectionUsesTypedRecallPromptAfterCompletion(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static fn (string $taskId): string => $taskId === 'TASK-1' ? 'complete' : 'incomplete',
        );

        ob_start();
        $exit = $command->run(['TASK-1']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('where would you invest additional engineering time', $output);
    }

    public function testTaskReflectionUsesTypedRecallPromptWhenReadyToClose(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static fn (string $taskId): string => 'ready_to_close',
        );

        ob_start();
        $exit = $command->run(['TASK-2', '--scope', 'task']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString("current task's stated completion bar", $output);
        self::assertStringContainsString('RETURN_TO_REVIEW', $output);
    }

    public function testReflectionDoesNotReplaceNormalReviewForIncompleteTask(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static fn (string $taskId): string => 'incomplete',
        );

        ob_start();
        $exit = $command->run(['TASK-3']);
        $output = (string) ob_get_clean();

        self::assertSame(1, $exit);
        self::assertSame('', $output);
    }

    public function testUnknownReflectionScopeFailsClosed(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static fn (string $taskId): string => 'complete',
        );

        self::assertSame(1, $command->run(['TASK-4', '--scope', 'everything']));
    }
}
