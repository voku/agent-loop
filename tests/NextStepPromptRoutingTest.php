<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentRecallCompiler\Reflection\NextStepPromptBuilder;

final class NextStepPromptRoutingTest extends TestCase
{
    public function testExplicitNextStepDelegationUsesTheInstalledRecallOwner(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-next-step-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o775, true));

        try {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', 'prompt', 'next-step'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $root,
            );
            if (!is_resource($process)) {
                self::fail('Could not launch the agent-loop CLI.');
            }

            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertSame(0, $exit);
            self::assertSame('', $stderr);
            self::assertSame((new NextStepPromptBuilder())->build() . "\n", $stdout);
            self::assertSame(['.', '..'], scandir($root));
        } finally {
            rmdir($root);
        }
    }
}
