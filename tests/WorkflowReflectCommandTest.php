<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowReflectCommand;
use voku\AgentRecallCompiler\Reflection\FutureWorkPromptBuilder;
use voku\AgentRecallCompiler\Reflection\FutureWorkScope;

final class WorkflowReflectCommandTest extends TestCase
{
    public function testProjectReflectionUsesRecallOwnerApiAfterCompletion(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            stateResolver: static fn (string $taskId): string => $taskId === 'TASK-1' ? 'complete' : 'incomplete',
        );

        ob_start();
        try {
            $exit = $command->run(['TASK-1']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(
            (new FutureWorkPromptBuilder())->build(FutureWorkScope::PROJECT) . "\n",
            $output,
        );
    }

    public function testTaskReflectionUsesRecallOwnerApiWhenReadyToClose(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            stateResolver: static fn (string $taskId): string => 'ready_to_close',
        );

        ob_start();
        try {
            $exit = $command->run(['TASK-2', '--scope', 'task']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame(
            (new FutureWorkPromptBuilder())->build(FutureWorkScope::TASK) . "\n",
            $output,
        );
    }

    public function testReflectionDoesNotReplaceNormalReviewForIncompleteTask(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            stateResolver: static fn (string $taskId): string => 'incomplete',
        );

        self::assertSame(1, $command->run(['TASK-3']));
    }

    public function testUnknownReflectionScopeFailsClosed(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            stateResolver: static fn (string $taskId): string => 'complete',
        );

        self::assertSame(1, $command->run(['TASK-4', '--scope', 'everything']));
    }
}
