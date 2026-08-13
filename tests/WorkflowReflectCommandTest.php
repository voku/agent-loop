<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowReflectCommand;

final class WorkflowReflectCommandTest extends TestCase
{
    public function testProjectReflectionDelegatesToRecallAfterCompletion(): void
    {
        $received = null;
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static function (array $args) use (&$received): int {
                $received = $args;

                return 0;
            },
            static fn (string $taskId): string => $taskId === 'TASK-1' ? 'complete' : 'incomplete',
        );

        self::assertSame(0, $command->run(['TASK-1']));
        self::assertSame(['prompt', 'future-work', '--scope', 'project'], $received);
    }

    public function testTaskReflectionDelegatesWhenReadyToClose(): void
    {
        $received = null;
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static function (array $args) use (&$received): int {
                $received = $args;

                return 0;
            },
            static fn (string $taskId): string => 'ready_to_close',
        );

        self::assertSame(0, $command->run(['TASK-2', '--scope', 'task']));
        self::assertSame(['prompt', 'future-work', '--scope', 'task'], $received);
    }

    public function testReflectionDoesNotReplaceNormalReviewForIncompleteTask(): void
    {
        $called = false;
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static function (array $args) use (&$called): int {
                $called = true;

                return 0;
            },
            static fn (string $taskId): string => 'incomplete',
        );

        self::assertSame(1, $command->run(['TASK-3']));
        self::assertFalse($called);
    }

    public function testUnknownReflectionScopeFailsClosed(): void
    {
        $command = new WorkflowReflectCommand(
            sys_get_temp_dir(),
            static fn (array $args): int => 0,
            static fn (string $taskId): string => 'complete',
        );

        self::assertSame(1, $command->run(['TASK-4', '--scope', 'everything']));
    }
}
