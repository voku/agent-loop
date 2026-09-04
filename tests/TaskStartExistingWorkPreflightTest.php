<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class TaskStartExistingWorkPreflightTest extends TestCase
{
    public function testTaskStartRequiresOverlapAuditBeforeCompetingImplementation(): void
    {
        $skill = (string) file_get_contents(
            dirname(__DIR__) . '/resources/skills/agent-loop-task-start/SKILL.md',
        );

        self::assertStringContainsString('Inspect overlap before invention.', $skill);
        self::assertStringContainsString('An open PR is not correctness evidence.', $skill);
        self::assertStringContainsString('try to **falsify it**', $skill);
        self::assertStringContainsString('close superseded work instead of creating a competing implementation', $skill);
        self::assertStringContainsString('external history is unavailable', $skill);
        self::assertStringContainsString('Do not turn this preflight into a new lifecycle state', $skill);
    }
}
