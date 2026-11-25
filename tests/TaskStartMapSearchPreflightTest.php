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

    public function testTaskStartInspectsActualRuntimeAndTestDependenciesBeforeFirstPlan(): void
    {
        $skill = (string) file_get_contents(
            dirname(__DIR__) . '/resources/skills/agent-loop-task-start/SKILL.md',
        );

        // Amy's Echo NG-54 needed two approved scope revisions solely to add the
        // service-worker precache and an existing browser test. The host should
        // inspect those integration paths before sealing its first Contract.
        self::assertStringContainsString('Before the first non-trivial PLAN, do one bounded read-only scope pass.', $skill);
        self::assertStringContainsString('regression or browser', $skill);
        self::assertStringContainsString('service-worker precache entry', $skill);
        self::assertStringContainsString('Derive these paths from repository evidence', $skill);
        self::assertStringContainsString('rather than serially', $skill);
        self::assertStringContainsString('named human approval', $skill);
    }

}
