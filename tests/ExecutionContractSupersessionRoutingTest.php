<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Run\RunPolicyEvaluator;

final class ExecutionContractSupersessionRoutingTest extends TestCase
{
    public function testBlockedExecutionContractRoutesToExplicitNonLossySupersessionDecision(): void
    {
        $evaluation = (new RunPolicyEvaluator())->evaluate(
            'SUPERSEDE-1',
            'governed',
            $this->references('blocked'),
            [],
        );

        self::assertSame('blocked', $evaluation->state);
        self::assertSame(RunPolicyEvaluation::KIND_DECISION_REQUIRED, $evaluation->nextActionKind);
        self::assertStringContainsString('Expand the governed scope.', $evaluation->nextAction);
        self::assertStringContainsString('workflow plan --supersede', $evaluation->nextAction);
        self::assertStringContainsString('explicit approval remains required', $evaluation->nextAction);
        self::assertStringNotContainsString('<path>', $evaluation->nextAction);
    }

    public function testRejectedExecutionContractUsesTheSameGovernedSupersessionBoundary(): void
    {
        $evaluation = (new RunPolicyEvaluator())->evaluate(
            'SUPERSEDE-1',
            'governed',
            $this->references('rejected'),
            [],
        );

        self::assertSame('blocked', $evaluation->state);
        self::assertSame(RunPolicyEvaluation::KIND_DECISION_REQUIRED, $evaluation->nextActionKind);
        self::assertStringContainsString('workflow plan --supersede', $evaluation->nextAction);
        self::assertStringContainsString('Expand the governed scope.', $evaluation->nextAction);
    }

    /** @return array<string, array<string, mixed>> */
    private function references(string $executionContractState): array
    {
        return [
            'contract' => ['state' => 'approved', 'revision' => 1, 'run_revision' => 1],
            'approval' => ['state' => 'current'],
            'session' => ['state' => 'active'],
            'recall' => ['state' => 'compiled'],
            'execution_contract' => [
                'state' => $executionContractState,
                'owner' => 'agent-loop',
                'reason' => 'Selected L2 policy cannot be satisfied by the approved Contract.',
                'minimum_contract_change' => 'Expand the governed scope.',
            ],
            'review' => ['state' => 'missing'],
            'learning' => ['state' => 'missing'],
            'verification' => ['state' => 'missing'],
        ];
    }
}
