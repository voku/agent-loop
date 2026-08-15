<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

/** @internal */
final class TaskStartMapSearchPreflightTest extends TestCase
{
    public function testCanonicalConsumerGuidanceBuildsSearchIndexBeforeApproval(): void
    {
        foreach ([
            dirname(__DIR__) . '/docs/quick-start.md',
            dirname(__DIR__) . '/docs/agents/skills/agent-loop-task-start/SKILL.md',
        ] as $path) {
            $content = (string) file_get_contents($path);
            $searchIndex = strpos($content, 'vendor/bin/agent-loop map search-index build');
            $approval = strpos($content, 'vendor/bin/agent-loop workflow approve');

            self::assertIsInt($searchIndex, $path);
            self::assertIsInt($approval, $path);
            self::assertLessThan($approval, $searchIndex, $path);
        }
    }
}
