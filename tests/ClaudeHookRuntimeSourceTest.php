<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class ClaudeHookRuntimeSourceTest extends TestCase
{
    public function testRuntimeProbeGuardsPhpFloorAndDoesNotScanArbitraryToolAutoloaders(): void
    {
        $root = dirname(__DIR__) . '/docs/agents/claude-hooks/hooks';
        $runtime = file_get_contents($root . '/runtime.php');

        self::assertIsString($runtime);
        self::assertStringContainsString('PHP_VERSION_ID < 80300', $runtime);
        self::assertStringContainsString("/tools/agent-loop/vendor/autoload.php", $runtime);
        self::assertStringNotContainsString("glob(", $runtime);
        self::assertStringNotContainsString("/tools/*/", $runtime);

        foreach (['context.php', 'pre_tool_use_policy.php'] as $entrypoint) {
            $content = file_get_contents($root . '/' . $entrypoint);
            self::assertIsString($content);
            self::assertStringContainsString("require __DIR__ . '/runtime.php'", $content);
        }
    }
}
