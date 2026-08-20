<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\HostCapability;
use voku\AgentLoop\Init\HostCapabilityMatrix;
use voku\AgentLoop\Init\HostCapabilityStatus;
use voku\AgentLoop\Init\InitAgent;

/**
 * @internal
 */
final class HostCapabilityMatrixTest extends TestCase
{
    public function testEveryCanonicalAgentDeclaresEveryCapabilityExactlyOnce(): void
    {
        foreach (InitAgent::canonicalNames() as $agent) {
            $rows = HostCapabilityMatrix::forAgent($agent);

            self::assertCount(count(HostCapability::cases()), $rows);
            self::assertSame(
                array_map(static fn (HostCapability $capability): string => $capability->value, HostCapability::cases()),
                array_map(static fn (array $row): string => $row['capability']->value, $rows),
            );
        }
    }

    public function testPortableSkillAndSubagentProjectionIsSupportedForEveryCanonicalAgent(): void
    {
        foreach (InitAgent::canonicalNames() as $agent) {
            self::assertSame(HostCapabilityStatus::Supported, HostCapabilityMatrix::status($agent, HostCapability::SkillProjection));
            self::assertSame(HostCapabilityStatus::Supported, HostCapabilityMatrix::status($agent, HostCapability::SubagentProjection));

            foreach ([HostCapability::SkillProjection, HostCapability::SubagentProjection] as $capability) {
                $description = HostCapabilityMatrix::describe($agent, $capability);
                self::assertSame('adapter-declared', $description['evidence']);
                self::assertNotSame('', $description['mechanism']);
            }
        }
    }

    public function testPolicyProjectionExistsOnlyWhereRepositoryPolicyCanBeRepresentedHonestly(): void
    {
        foreach (['codex', 'claude', 'opencode'] as $agent) {
            self::assertSame(HostCapabilityStatus::Supported, HostCapabilityMatrix::status($agent, HostCapability::PolicyProjection));
            self::assertSame(
                'adapter-declared',
                HostCapabilityMatrix::describe($agent, HostCapability::PolicyProjection)['evidence'],
            );
        }

        foreach (['copilot', 'gemini', 'antigravity'] as $agent) {
            self::assertSame(HostCapabilityStatus::Unsupported, HostCapabilityMatrix::status($agent, HostCapability::PolicyProjection));
            self::assertSame(
                'no agent-loop host policy projector',
                HostCapabilityMatrix::describe($agent, HostCapability::PolicyProjection)['mechanism'],
            );
        }
    }

    public function testHookBackedDisciplineStaysDegradedUntilHostRuntimeIsObserved(): void
    {
        $hookBacked = [
            HostCapability::SessionBootstrap,
            HostCapability::SubagentBootstrap,
            HostCapability::PreToolGuardrail,
            HostCapability::RepositoryHooks,
        ];

        foreach ($hookBacked as $capability) {
            foreach (['codex', 'claude'] as $agent) {
                self::assertSame(HostCapabilityStatus::Degraded, HostCapabilityMatrix::status($agent, $capability));
                self::assertSame(
                    'adapter-declared;live-runtime-unverified',
                    HostCapabilityMatrix::describe($agent, $capability)['evidence'],
                );
            }

            foreach (['opencode', 'copilot', 'gemini', 'antigravity'] as $agent) {
                self::assertSame(HostCapabilityStatus::Unsupported, HostCapabilityMatrix::status($agent, $capability));
                $description = HostCapabilityMatrix::describe($agent, $capability);
                self::assertSame('no-agent-loop-projector', $description['evidence']);
                self::assertSame('no agent-loop host-native projector', $description['mechanism']);
            }
        }
    }

    public function testKnownNativeMechanismsAreExplainedWithoutClaimingRuntimeExecution(): void
    {
        self::assertSame(
            'SKILL.md -> GitHub Copilot skills directory',
            HostCapabilityMatrix::describe('copilot', HostCapability::SkillProjection)['mechanism'],
        );
        self::assertSame(
            'SKILL.md -> OpenCode .opencode/skills directory',
            HostCapabilityMatrix::describe('opencode', HostCapability::SkillProjection)['mechanism'],
        );
        self::assertSame(
            '.codex/rules/agent-loop.rules executable policy',
            HostCapabilityMatrix::describe('codex', HostCapability::PolicyProjection)['mechanism'],
        );
        self::assertSame(
            'Codex hooks.json + repository-local command hooks',
            HostCapabilityMatrix::describe('codex', HostCapability::PreToolGuardrail)['mechanism'],
        );
        self::assertSame(
            'Claude settings.json#hooks + repository-local command hooks',
            HostCapabilityMatrix::describe('claude', HostCapability::SessionBootstrap)['mechanism'],
        );
    }

    public function testUnknownCanonicalAgentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown canonical agent: imaginary-agent');

        HostCapabilityMatrix::forAgent('imaginary-agent');
    }
}
