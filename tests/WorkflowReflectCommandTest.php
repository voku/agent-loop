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
        $root = sys_get_temp_dir() . '/agent-loop-reflect-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $command = new WorkflowReflectCommand(
            $root,
            stateResolver: static fn (string $taskId): string => $taskId === 'TASK-1' ? 'complete' : 'incomplete',
        );

        ob_start();
        try {
            $exit = $command->run(['TASK-1']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        try {
            self::assertSame(0, $exit);
            self::assertSame(
                (new FutureWorkPromptBuilder())->build(FutureWorkScope::PROJECT) . "\n",
                $output,
            );
            self::assertSame(['.', '..'], scandir($root));
        } finally {
            rmdir($root);
        }
    }

    public function testProjectReflectionRequiresCompletionWhenReadyToClose(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-reflect-' . bin2hex(random_bytes(8));
        mkdir($root, 0777, true);
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $code = sprintf(
            'require %s; $command = new \\voku\\AgentLoop\\Workflow\\WorkflowReflectCommand(%s, stateResolver: static fn (string $taskId): string => %s); exit($command->run([%s]));',
            var_export($autoload, true),
            var_export($root, true),
            var_export('ready_to_close', true),
            var_export('TASK-5', true),
        );
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!is_resource($process)) {
            rmdir($root);
            self::fail('Could not start the workflow reflect subprocess.');
        }

        try {
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertSame(1, $exit);
            self::assertSame('', $stdout);
            self::assertStringContainsString(
                'Project reflection requires a completed task; current state is ready_to_close. Complete the normal close workflow first.',
                (string) $stderr,
            );
            self::assertSame(['.', '..'], scandir($root));
        } finally {
            rmdir($root);
        }
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
