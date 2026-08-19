<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluator;

final class RunPolicyEvaluatorTest extends TestCase
{
    public function testMissingWorkflowPointsToPlanWithoutMutationAuthority(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'ABC-123',
            'legacy_inferred',
            $this->references(
                contract: 'missing',
                approval: 'unavailable',
                session: 'missing',
                recall: 'missing',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );

        self::assertSame('incomplete', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertFalse($policy->ordinaryCloseAllowed);
        self::assertSame([], $policy->blockers);
        self::assertStringContainsString('workflow plan ABC-123', $policy->nextAction);
    }

    public function testApprovedPlannedWorkPointsToEnterWithoutMutationAuthority(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'ABC-123',
            'planned',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'missing',
                recall: 'missing',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );

        self::assertSame('incomplete', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertFalse($policy->ordinaryCloseAllowed);
        self::assertSame('agent-loop enter ABC-123', $policy->nextAction);
    }

    public function testApprovedContractWithoutCurrentApprovalDoesNotRouteToEnter(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'ABC-123',
            'planned',
            $this->references(
                contract: 'approved',
                approval: 'missing',
                session: 'missing',
                recall: 'missing',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'unavailable',
            ),
            [],
        );

        self::assertSame('incomplete', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertSame('agent-loop workflow status ABC-123 --format=json', $policy->nextAction);
    }

    public function testInactiveGovernedSessionRoutesToEnterBeforeExecutionContract(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'ABC-123',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'missing',
                recall: 'compiled',
                executionContract: 'missing',
                verification: 'pending_close',
                review: 'missing',
                learning: 'missing',
            ),
            [],
        );

        self::assertSame('incomplete', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertSame('agent-loop enter ABC-123', $policy->nextAction);
    }

    public function testGovernedRunAuthorizesMutationButSurfacesDeterministicValidationFirst(): void
    {
        $references = $this->references(
            contract: 'approved',
            approval: 'current',
            session: 'active',
            recall: 'compiled',
            executionContract: 'not_required',
            verification: 'blocked',
            review: 'missing',
            learning: 'missing',
        );
        $references['verification']['gate'] = 'validation';
        $references['verification']['action'] = 'composer test';

        $policy = (new RunPolicyEvaluator())->evaluate('ABC-123', 'governed', $references, []);

        self::assertSame('incomplete', $policy->state);
        self::assertTrue($policy->mutationAllowed);
        self::assertFalse($policy->ordinaryCloseAllowed);
        self::assertSame('agent-loop finish ABC-123', $policy->nextAction);
    }

    public function testReadyToCloseNeverReopensMutation(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'ABC-123',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'passed',
                review: 'ok',
                learning: 'decided',
            ),
            [],
        );

        self::assertSame('ready_to_close', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertTrue($policy->ordinaryCloseAllowed);
        self::assertSame('agent-loop finish ABC-123', $policy->nextAction);
    }

    public function testCompleteRunKeepsCloseIdempotentWithoutReopeningMutation(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'ABC-123',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'done',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'passed',
                review: 'ok',
                learning: 'decided',
            ),
            [],
        );

        self::assertSame('complete', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertTrue($policy->ordinaryCloseAllowed);
        self::assertSame('none', $policy->nextAction);
    }

    public function testDisagreementFailsClosedWithTheSameObservableBlocker(): void
    {
        $disagreement = [
            'code' => 'run.contract_digest_mismatch',
            'owner' => 'agent-loop',
            'message' => 'Governed Run Contract digest does not match the current Contract artifact.',
        ];

        $policy = (new RunPolicyEvaluator())->evaluate(
            'ABC-123',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'missing',
                learning: 'missing',
            ),
            [$disagreement],
        );

        self::assertSame('blocked', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertFalse($policy->ordinaryCloseAllowed);
        self::assertSame([$disagreement], $policy->blockers);
        // The fail-closed properties above are the point of this test. The
        // action itself must not be the read-only inspection command, which
        // cannot alter the state that produced the disagreement.
        self::assertSame('host_work', $policy->nextActionKind);
        self::assertStringNotContainsString('workflow manifest', $policy->nextAction);
        self::assertStringContainsString('run.contract_digest_mismatch', $policy->nextAction);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function references(
        string $contract,
        string $approval,
        string $session,
        string $recall,
        string $executionContract,
        string $verification,
        string $review,
        string $learning,
    ): array {
        return [
            'session' => ['owner' => 'agent-session', 'state' => $session],
            'contract' => ['owner' => 'agent-loop', 'state' => $contract],
            'approval' => ['owner' => 'agent-loop', 'state' => $approval],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => $recall],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => $executionContract],
            'verification' => ['owner' => 'agent-loop', 'state' => $verification],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => $review],
            'learning' => ['owner' => 'agent-learning', 'state' => $learning],
        ];
    }
}
