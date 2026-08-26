<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Run\RunPolicyEvaluator;
use voku\AgentLoop\Workflow\Transparency\ApprovedScope;

final class HumanDecisionUxDogfoodTest extends TestCase
{
    public function testCrossCuttingLifecycleOnlyInterruptsAtGenuineAuthorityBoundaries(): void
    {
        $evaluator = new RunPolicyEvaluator();

        $plan = $evaluator->evaluate('UX-1', 'legacy_inferred', [
            'contract' => ['owner' => 'agent-loop', 'state' => 'missing'],
        ], []);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $plan->nextActionKind);

        $approval = $evaluator->evaluate('UX-1', 'planned', [
            'contract' => ['owner' => 'agent-loop', 'state' => 'candidate'],
            'approval' => ['owner' => 'agent-loop', 'state' => 'missing'],
        ], []);
        self::assertSame(RunPolicyEvaluation::KIND_DECISION_REQUIRED, $approval->nextActionKind);

        $base = [
            'session' => ['owner' => 'agent-session', 'state' => 'active'],
            'contract' => [
                'owner' => 'agent-loop',
                'state' => 'approved',
                'revision' => 1,
                'run_revision' => 1,
            ],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'missing'],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'missing'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'missing'],
            'verification' => ['owner' => 'agent-loop', 'state' => 'pending_close'],
        ];

        $l1 = $evaluator->evaluate('UX-1', 'governed', $base, []);
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND_TEMPLATE, $l1->nextActionKind);

        $reviewReferences = $base;
        $reviewReferences['execution_contract']['state'] = 'ready';
        $reviewReferences['review'] = [
            'owner' => 'agent-recall-compiler',
            'state' => 'unacknowledged',
            'source' => ['path' => '.agent-loop/review.json', 'sha256' => 'sha256:' . str_repeat('a', 64)],
        ];
        $review = $evaluator->evaluate('UX-1', 'governed', $reviewReferences, []);
        self::assertSame(RunPolicyEvaluation::KIND_DECISION_REQUIRED, $review->nextActionKind);

        $learningReferences = $reviewReferences;
        $learningReferences['review']['state'] = 'ok';
        $learning = $evaluator->evaluate('UX-1', 'governed', $learningReferences, []);
        self::assertSame(RunPolicyEvaluation::KIND_DECISION_REQUIRED, $learning->nextActionKind);

        $scope = ApprovedScope::fromEntries(['src/Workflow']);
        self::assertTrue($scope->contains('src/Workflow/HostFrontDoorApplication.php'));
        self::assertTrue($scope->contains('src/Workflow/WorkflowHumanDecisionProjector.php'));
        self::assertFalse($scope->contains('src/Run/RunPolicyEvaluator.php'));

        $humanInterruptions = array_values(array_filter(
            [$plan, $approval, $l1, $review, $learning],
            static fn (RunPolicyEvaluation $result): bool => $result->nextActionKind === RunPolicyEvaluation::KIND_DECISION_REQUIRED,
        ));

        self::assertCount(3, $humanInterruptions);
        self::assertSame(
            [
                'agent-loop workflow approve UX-1 --by <named-actor>',
                'agent-loop finish UX-1 --reviewed-report-sha256 sha256:' . str_repeat('a', 64) . ' --by <actor>',
                'agent-loop finish UX-1 --learning <no_durable_learning|findings_recorded|follow_up_required> --learning-reason <learning-reason> --by <actor>',
            ],
            array_map(static fn (RunPolicyEvaluation $result): string => $result->nextAction, $humanInterruptions),
        );
    }
}
