<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Run\RunPolicyEvaluator;

final class RunPolicyRecoveryConvergenceTest extends TestCase
{
    public function testBlockedVerificationDoesNotRouteToReadOnlyDiagnostic(): void
    {
        $references = [
            'session' => ['owner' => 'agent-session', 'state' => 'active'],
            'contract' => ['owner' => 'agent-loop', 'state' => 'approved'],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'not_required'],
            'verification' => [
                'owner' => 'agent-loop',
                'state' => 'failed',
                'gate' => 'artifact_integrity',
                'reason' => 'Candidate artifact digest does not match the observed implementation.',
            ],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'ok'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'decided'],
        ];

        $policy = (new RunPolicyEvaluator())->evaluate('RECOVERY-464', 'governed', $references, []);

        self::assertSame('blocked', $policy->state);
        self::assertFalse($policy->mutationAllowed);
        self::assertSame('host_work', $policy->nextActionKind);
        self::assertStringContainsString('verification.artifact_integrity', $policy->nextAction);
        self::assertStringContainsString('Candidate artifact digest does not match', $policy->nextAction);
        self::assertStringNotContainsString('workflow status', $policy->nextAction);
        self::assertStringNotContainsString('workflow manifest', $policy->nextAction);
    }
}
