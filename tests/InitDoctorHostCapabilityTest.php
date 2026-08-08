<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitDoctorCommand;

/**
 * @internal
 */
final class InitDoctorHostCapabilityTest extends TestCase
{
    public function testDoctorReportsCurrentHostCapabilitySupportWithoutWritingState(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-init-host-capabilities-' . bin2hex(random_bytes(6));
        mkdir($root, 0o775, true);

        try {
            $before = scandir($root);
            self::assertIsArray($before);

            ob_start();
            $exit = (new InitDoctorCommand($root))->run([]);
            $output = (string) ob_get_clean();

            self::assertSame(0, $exit);
            self::assertStringContainsString('Host capabilities [codex]: skills=supported, subagents=supported', $output);
            self::assertStringContainsString('Host capabilities [claude]: skills=supported, subagents=supported', $output);
            self::assertStringContainsString('Host capabilities [copilot]: skills=supported, subagents=supported', $output);
            self::assertStringContainsString('repository-hooks=unsupported', $output);
            self::assertStringContainsString('Host capabilities [antigravity]: skills=supported, subagents=supported', $output);
            self::assertSame($before, scandir($root));
        } finally {
            rmdir($root);
        }
    }
}
