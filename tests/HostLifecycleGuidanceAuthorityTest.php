<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class HostLifecycleGuidanceAuthorityTest extends TestCase
{
    /**
     * The Slice E falsification pass found ordinary-path guidance still carrying
     * an old phase machine and discovery-before-approval choreography. Guard the
     * concrete shapes that actually drifted; this is not a prose policy engine.
     */
    public function testOrdinaryHostGuidanceRoutesToCanonicalLifecycleResults(): void
    {
        $root = dirname(__DIR__);
        $paths = [
            $root . '/docs/agents/skills/agent-loop-workflow/SKILL.md',
            $root . '/docs/agents/skills/agent-loop-task-start/SKILL.md',
            $root . '/docs/agents/LIFECYCLE.md',
            $root . '/docs/quick-start.md',
        ];

        foreach ($paths as $path) {
            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('next_action', $contents, $path . ' must route through the canonical next step');
            self::assertStringNotContainsString(
                'PLAN -> APPROVE -> ENTER/PREPARE',
                $contents,
                $path . ' must not restore the retired prose phase machine',
            );
            self::assertStringNotContainsString(
                'map build --paths=src,tests',
                $contents,
                $path . ' must not pre-empt the discovery owner with a host-side map rule',
            );
            self::assertStringNotContainsString(
                'workflow close --status done',
                $contents,
                $path . ' must not restore the pre-finish ordinary close choreography',
            );
        }
    }

    public function testTaskStartDoesNotClaimApprovalCreatesPreparedWorkingState(): void
    {
        $path = dirname(__DIR__) . '/docs/agents/skills/agent-loop-task-start/SKILL.md';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        foreach ([
            'approval prepares the governed Run/Session',
            'workflow approve` creates the governed working state',
            'initial map/Search preflight belongs before approval',
        ] as $retiredClaim) {
            self::assertStringNotContainsString($retiredClaim, $contents);
        }

        self::assertMatchesRegularExpression(
            '/Approval records authority for the exact Contract\s+revision/',
            $contents,
        );
        self::assertMatchesRegularExpression(
            '/preparation happens deterministically behind\s+`enter`/',
            $contents,
        );
    }

    public function testLifecycleReferenceKeepsApprovalAndEnterOwnershipDistinct(): void
    {
        $path = dirname(__DIR__) . '/docs/agents/LIFECYCLE.md';
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringContainsString('Approval is an authority boundary.', $contents);
        self::assertStringContainsString('That approval is the ordinary human task-authority gate.', $contents);
        self::assertMatchesRegularExpression(
            '/Approval itself does \*\*not\*\*\s+allocate the governed Run, Session or Recall output\./',
            $contents,
        );
        self::assertStringContainsString('`enter` owns deterministic post-approval preparation/reconciliation.', $contents);
        self::assertMatchesRegularExpression(
            '/review acknowledgement, Learning\s+judgment, Recall outcome logging, local commits and closeout inside the same\s+Contract may be delegated to the acting agent\./',
            $contents,
        );
    }
}
