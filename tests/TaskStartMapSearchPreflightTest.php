<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class TaskStartMapSearchPreflightTest extends TestCase
{
    public function testCanonicalConsumerGuidanceDoesNotPreemptDiscoveryOwnerBeforeApproval(): void
    {
        foreach ([
            dirname(__DIR__) . '/docs/quick-start.md',
            dirname(__DIR__) . '/resources/skills/agent-loop-task-start/SKILL.md',
            dirname(__DIR__) . '/resources/skills/agent-loop-workflow/SKILL.md',
        ] as $path) {
            $content = (string) file_get_contents($path);

            self::assertStringContainsString('next_action', $content, $path);
            self::assertStringNotContainsString(
                'vendor/bin/agent-loop map search-index build',
                $content,
                $path . ' must not teach an unconditional search-index preflight before approval',
            );
            self::assertStringNotContainsString(
                'vendor/bin/agent-loop map build --paths=src,tests',
                $content,
                $path . ' must let the discovery owner surface the required repair through next_action',
            );
        }
    }
}
