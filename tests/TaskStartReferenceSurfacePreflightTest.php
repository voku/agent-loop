<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class TaskStartReferenceSurfacePreflightTest extends TestCase
{
    public function testReferenceDerivedTaskMustInventoryUpstreamBeforePlan(): void
    {
        $skill = (string) file_get_contents(
            dirname(__DIR__) . '/docs/agents/skills/agent-loop-task-start/SKILL.md',
        );

        self::assertStringContainsString('## External Reference Preflight', $skill);
        self::assertStringContainsString('Use this preflight **only** when the task is explicitly defined relative to an', $skill);
        self::assertStringContainsString('Before running `workflow plan` or sealing scope for approval:', $skill);
        self::assertStringContainsString('state what is included, excluded, and still unknown', $skill);
        self::assertStringContainsString('distinguish a direct port from an adaptation', $skill);
        self::assertStringContainsString('do not claim parity from a partial inventory', $skill);
        self::assertStringContainsString('`adapt upstream checks` must not silently become `port upstream', $skill);
        self::assertStringContainsString('intentionally scope to one rule or', $skill);
        self::assertStringContainsString('record that surface as unknown', $skill);
        self::assertStringContainsString('not a new lifecycle state', $skill);
        self::assertStringContainsString('Do not turn it', $skill);
    }
}
