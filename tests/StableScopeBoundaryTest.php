<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\Transparency\ApprovedScope;

final class StableScopeBoundaryTest extends TestCase
{
    public function testLaterSiblingInsideApprovedSubsystemDoesNotRequireScopeExpansion(): void
    {
        $scope = ApprovedScope::fromEntries(['src/Workflow']);

        $initialEvidence = $scope->partition([
            'src/Workflow/HostFrontDoorApplication.php',
            'src/Workflow/WorkflowHumanDecisionProjector.php',
        ]);
        self::assertSame([], $initialEvidence['outside']);

        $laterTouchedSibling = $scope->partition([
            'src/Workflow/WorkflowHumanReviewCommand.php',
        ]);
        self::assertSame(
            ['src/Workflow/WorkflowHumanReviewCommand.php'],
            $laterTouchedSibling['inside'],
        );
        self::assertSame([], $laterTouchedSibling['outside']);

        self::assertFalse(
            $scope->contains('src/Run/RunPolicyEvaluator.php'),
            'A stable subsystem boundary must not become repository-wide blanket approval.',
        );
    }
}
