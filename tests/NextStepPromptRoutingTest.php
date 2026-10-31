<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dispatcher;
use voku\AgentRecallCompiler\Reflection\NextStepPromptBuilder;

final class NextStepPromptRoutingTest extends TestCase
{
    public function testExplicitNextStepDelegationUsesTheInstalledRecallOwner(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-next-step-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o775, true));

        try {
            ob_start();
            try {
                $exit = (new Dispatcher($root))->run(['agent-loop', 'prompt', 'next-step']);
                $output = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }

            self::assertSame(0, $exit);
            self::assertSame((new NextStepPromptBuilder())->build() . "\n", $output);
            self::assertSame(['.', '..'], scandir($root));
        } finally {
            rmdir($root);
        }
    }
}
