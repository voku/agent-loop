<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowStartCommand;

final class WorkflowStartCommandTest extends TestCase
{
    public function testStartDelegatesSessionAndRecall(): void
    {
        /** @var array<string, list<string>> $calls */
        $calls = [];
        $cmd = new WorkflowStartCommand(sys_get_temp_dir(), static function (array $argv) use (&$calls): int { $calls['session'] = $argv; return 0; }, static function (array $argv) use (&$calls): int { $calls['recall'] = $argv; return 0; });
        ob_start(); $exit = $cmd->run(['ABC-123','--by','lars','--learning-root','infra/doc/agent-learning','--file','src/Foo.php']); $out=(string)ob_get_clean();
        self::assertSame(0, $exit);
        self::assertSame(['start','--task','ABC-123','--by','lars'], $calls['session']);
        self::assertSame(['compile','--root','infra/doc/agent-learning','--task','ABC-123','--file','src/Foo.php'], $calls['recall']);
        self::assertStringContainsString('workflow start: recall compile completed', $out);
    }

    public function testStartSupportsRepeatedFileAndBaseCommit(): void
    {
        /** @var array<string, list<string>> $calls */
        $calls = [];
        $cmd = new WorkflowStartCommand(sys_get_temp_dir(), static function (array $argv) use (&$calls): int { $calls['session'] = $argv; return 0; }, static function (array $argv) use (&$calls): int { $calls['recall'] = $argv; return 0; });
        ob_start(); $exit = $cmd->run(['ABC-123','--by','lars','--root','learn','--file','a.php','--file','b.php','--base-commit','abc']); ob_end_clean();
        self::assertSame(0, $exit);
        self::assertSame(['start','--task','ABC-123','--by','lars','--base-commit','abc'], $calls['session']);
        self::assertSame(['compile','--root','learn','--task','ABC-123','--file','a.php','--file','b.php'], $calls['recall']);
    }

    public function testStartValidationAndStops(): void
    {
        $cmd = new WorkflowStartCommand(sys_get_temp_dir(), static fn(array $a): int => 7, static fn(array $a): int => 0);
        ob_start(); self::assertSame(1, $cmd->run(['ABC-123','--learning-root','x','--file','f'])); ob_end_clean();
        ob_start(); self::assertSame(1, $cmd->run(['ABC-123','--by','lars','--file','f'])); ob_end_clean();
        ob_start(); self::assertSame(1, $cmd->run(['ABC-123','--by','lars','--learning-root','x'])); ob_end_clean();
        ob_start(); self::assertSame(7, $cmd->run(['ABC-123','--by','lars','--learning-root','x','--file','f'])); ob_end_clean();
    }

    public function testStartStopsIfRecallFails(): void
    {
        $cmd = new WorkflowStartCommand(sys_get_temp_dir(), static fn(array $a): int => 0, static fn(array $a): int => 8);
        ob_start(); $exit = $cmd->run(['ABC-123','--by','lars','--learning-root','x','--file','f']); ob_end_clean();
        self::assertSame(8, $exit);
    }
}
