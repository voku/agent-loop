<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluation;
use voku\AgentLoop\Run\RunPolicyEvaluator;

final class CanonicalActionKindTest extends TestCase
{
    public function testUnplannedTaskUsesOnePlaceholderNotationAndNamesHumanDecision(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
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

        self::assertSame(RunPolicyEvaluation::KIND_HUMAN_DECISION, $policy->nextActionKind);
        self::assertStringContainsString('--by <actor>', $policy->nextAction);
        self::assertStringContainsString('--file <path>', $policy->nextAction);
        self::assertStringContainsString('--goal <goal>', $policy->nextAction);
        self::assertStringContainsString('--validation <validation>', $policy->nextAction);
        self::assertStringNotContainsString('"..."', $policy->nextAction);
    }

    public function testLearningDispositionIsAHumanDecisionRatherThanAnExecutableCommand(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
            'governed',
            $this->references(
                contract: 'approved',
                approval: 'current',
                session: 'active',
                recall: 'compiled',
                executionContract: 'not_required',
                verification: 'pending_close',
                review: 'ok',
                learning: 'missing',
            ),
            [],
        );

        self::assertSame(RunPolicyEvaluation::KIND_HUMAN_DECISION, $policy->nextActionKind);
        self::assertStringContainsString('--learning <no_durable_learning|findings_recorded|follow_up_required>', $policy->nextAction);
        self::assertStringContainsString('--learning-reason <learning-reason>', $policy->nextAction);
    }

    public function testExecutableAndTerminalStepsKeepDistinctKinds(): void
    {
        $enter = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
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
        self::assertSame(RunPolicyEvaluation::KIND_COMMAND, $enter->nextActionKind);
        self::assertSame('agent-loop enter E5-001', $enter->nextAction);

        $complete = (new RunPolicyEvaluator())->evaluate(
            'E5-001',
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
        self::assertSame(RunPolicyEvaluation::KIND_NONE, $complete->nextActionKind);
        self::assertSame('none', $complete->nextAction);
    }

    /** @return array<string, array<string, mixed>> */
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
