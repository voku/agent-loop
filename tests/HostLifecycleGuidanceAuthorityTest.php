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
    /**
     * Guidance must route to its authority, not restate it.
     *
     * `agent-loop-workflow`'s own description says to obey the lifecycle kernel's
     * structured next step "instead of reproducing workflow policy in host prose",
     * and its body carried a full copy of the `next_action_kind` treatment contract
     * anyway - a rule the always-on `AGENTS.md` router states and the `enter`/`finish`
     * result itself returns. Three copies of one rule is two chances to drift, and
     * the copy that costs tokens is the one a session has to load.
     *
     * Using a kind in context ("when `finish` returns `command_template`, fill ...")
     * is guidance and stays. Re-declaring what the kinds mean is the duplication.
     */
    public function testOrdinaryGuidanceDoesNotRestateTheNextActionKindContract(): void
    {
        $skill = file_get_contents(
            dirname(__DIR__) . '/resources/skills/agent-loop-workflow/SKILL.md',
        );

        self::assertIsString($skill);
        self::assertStringNotContainsString('`next_action_kind` has one treatment contract', $skill);
        self::assertStringNotContainsString('execute `next_action` as written', $skill);
        self::assertStringNotContainsString('no further lifecycle action is required', $skill);
        self::assertStringContainsString('`AGENTS.md` already defines how to treat each kind', $skill);
        // The routing itself must survive the subtraction.
        self::assertStringContainsString('next_action', $skill);
    }

    /** The rule this skill defers to has to actually be in the always-on router. */
    public function testTheRouterStillCarriesTheContractTheSkillDefersTo(): void
    {
        $router = file_get_contents(dirname(__DIR__) . '/AGENTS.md');

        self::assertIsString($router);
        foreach (['command', 'command_template', 'decision_required', 'host_work', 'none'] as $kind) {
            self::assertStringContainsString(
                $kind,
                $router,
                'Deferring to the router only works while the router states it.',
            );
        }
    }

    public function testOrdinaryHostGuidanceRoutesToCanonicalLifecycleResults(): void
    {
        $root = dirname(__DIR__);
        $paths = [
            $root . '/resources/skills/agent-loop-workflow/SKILL.md',
            $root . '/resources/skills/agent-loop-task-start/SKILL.md',
            $root . '/docs/workflow/lifecycle.md',
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
        $path = dirname(__DIR__) . '/resources/skills/agent-loop-task-start/SKILL.md';
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
        $path = dirname(__DIR__) . '/docs/workflow/lifecycle.md';
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
