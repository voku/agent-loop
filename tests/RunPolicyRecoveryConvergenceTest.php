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

    public function testUnstructuredRepairActionFailsClosed(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'RECOVERY-464',
            'governed',
            [],
            [[
                'code' => 'verification.readiness_unreadable',
                'owner' => 'agent-loop',
                'message' => 'Validation evidence is unreadable.',
                'repair_action' => 'agent-loop repair RECOVERY-464',
            ]],
        );

        self::assertSame('agent-loop repair RECOVERY-464', $policy->nextAction);
        self::assertNull($policy->nextActionInvocation);
    }

    public function testOwnerStructuredRepairActionGetsTypedInvocation(): void
    {
        $policy = (new RunPolicyEvaluator())->evaluate(
            'RECOVERY-464',
            'governed',
            [],
            [[
                'code' => 'verification.readiness_unreadable',
                'owner' => 'agent-loop',
                'message' => 'Validation evidence is unreadable.',
                'repair_action' => 'agent-loop repair RECOVERY-464',
                'repair_invocation' => [
                    'executable' => 'agent-loop',
                    'arguments' => ['repair', 'RECOVERY-464'],
                    'template' => false,
                ],
            ]],
        );

        self::assertSame('agent-loop repair RECOVERY-464', $policy->nextAction);
        self::assertNotNull($policy->nextActionInvocation);
        self::assertSame(['repair', 'RECOVERY-464'], $policy->nextActionInvocation->arguments);
        self::assertFalse($policy->nextActionInvocation->template);
    }

    public function testOpaqueProjectValidationCommandFailsClosed(): void
    {
        $references = [
            'session' => ['owner' => 'agent-session', 'state' => 'active'],
            'contract' => ['owner' => 'agent-loop', 'state' => 'approved'],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'not_required'],
            'verification' => [
                'owner' => 'agent-loop',
                'state' => 'blocked',
                'gate' => 'recall_outcomes',
                'action' => 'docker compose run --rm project-check "quoted value"',
            ],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'ok'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'decided'],
        ];

        $policy = (new RunPolicyEvaluator())->evaluate('RECOVERY-464', 'governed', $references, []);

        self::assertSame('docker compose run --rm project-check "quoted value"', $policy->nextAction);
        self::assertSame('command', $policy->nextActionKind);
        self::assertNull($policy->nextActionInvocation);
    }

    public function testOwnerStructuredVerificationActionGetsTypedInvocation(): void
    {
        $references = [
            'session' => ['owner' => 'agent-session', 'state' => 'active'],
            'contract' => ['owner' => 'agent-loop', 'state' => 'approved'],
            'approval' => ['owner' => 'agent-loop', 'state' => 'current'],
            'recall' => ['owner' => 'agent-recall-compiler', 'state' => 'compiled'],
            'execution_contract' => ['owner' => 'agent-loop', 'state' => 'not_required'],
            'verification' => [
                'owner' => 'agent-loop',
                'state' => 'blocked',
                'gate' => 'recall_outcomes',
                'action' => 'agent-loop repair RECOVERY-464',
                'action_invocation' => [
                    'executable' => 'agent-loop',
                    'arguments' => ['repair', 'RECOVERY-464'],
                    'template' => false,
                ],
            ],
            'review' => ['owner' => 'agent-recall-compiler', 'state' => 'ok'],
            'learning' => ['owner' => 'agent-learning', 'state' => 'decided'],
        ];

        $policy = (new RunPolicyEvaluator())->evaluate('RECOVERY-464', 'governed', $references, []);

        self::assertSame('agent-loop repair RECOVERY-464', $policy->nextAction);
        self::assertSame(['repair', 'RECOVERY-464'], $policy->nextActionInvocation?->arguments);
        self::assertSame('command', $policy->nextActionKind);
    }
}
