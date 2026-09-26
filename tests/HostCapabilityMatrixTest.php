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

    public function testReadOnlySubagentEnforcementIsProjectedOnlyWhereOwnedNatively(): void
    {
        self::assertSame(
            HostCapabilityStatus::Supported,
            HostCapabilityMatrix::status('codex', HostCapability::SubagentReadOnlyEnforcement),
        );
        $codex = HostCapabilityMatrix::describe('codex', HostCapability::SubagentReadOnlyEnforcement);
        self::assertSame('adapter-declared', $codex['evidence']);
        self::assertSame(
            'canonical subagent mutation: read-only -> Codex sandbox_mode = read-only',
            $codex['mechanism'],
        );

        self::assertSame(
            HostCapabilityStatus::Supported,
            HostCapabilityMatrix::status('cursor', HostCapability::SubagentReadOnlyEnforcement),
        );
        $cursor = HostCapabilityMatrix::describe('cursor', HostCapability::SubagentReadOnlyEnforcement);
        self::assertSame('adapter-declared', $cursor['evidence']);
        self::assertSame(
            'canonical subagent mutation: read-only -> Cursor readonly = true',
            $cursor['mechanism'],
        );

        foreach (['claude', 'opencode', 'copilot', 'gemini', 'antigravity'] as $agent) {
            self::assertSame(
                HostCapabilityStatus::Unsupported,
                HostCapabilityMatrix::status($agent, HostCapability::SubagentReadOnlyEnforcement),
            );
            $description = HostCapabilityMatrix::describe($agent, HostCapability::SubagentReadOnlyEnforcement);
            self::assertSame('no-agent-loop-projector', $description['evidence']);
            self::assertSame(
                'no agent-loop read-only subagent enforcement projector',
                $description['mechanism'],
            );
        }
    }

    public function testPolicyProjectionExistsOnlyWhereRepositoryPolicyCanBeRepresentedHonestly(): void
    {
        foreach (['codex', 'claude', 'opencode', 'cursor'] as $agent) {
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

        foreach ([HostCapability::PreToolGuardrail, HostCapability::RepositoryHooks] as $capability) {
            self::assertSame(HostCapabilityStatus::Degraded, HostCapabilityMatrix::status('cursor', $capability));
            self::assertSame(
                'adapter-declared;live-runtime-unverified',
                HostCapabilityMatrix::describe('cursor', $capability)['evidence'],
            );
        }

        foreach ([HostCapability::SessionBootstrap, HostCapability::SubagentBootstrap] as $capability) {
            self::assertSame(HostCapabilityStatus::Unsupported, HostCapabilityMatrix::status('cursor', $capability));
            self::assertSame(
                'no-agent-loop-projector',
                HostCapabilityMatrix::describe('cursor', $capability)['evidence'],
            );
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
            'SKILL.md -> Cursor .cursor/skills directory',
            HostCapabilityMatrix::describe('cursor', HostCapability::SkillProjection)['mechanism'],
        );
        self::assertSame(
            'canonical subagent -> Cursor .cursor/agents Markdown definition',
            HostCapabilityMatrix::describe('cursor', HostCapability::SubagentProjection)['mechanism'],
        );
        self::assertSame(
            '.codex/rules/agent-loop.rules forbidden direct publication command prefixes',
            HostCapabilityMatrix::describe('codex', HostCapability::PolicyProjection)['mechanism'],
        );
        self::assertSame(
            'Codex hooks.json + repository-local command hooks',
            HostCapabilityMatrix::describe('codex', HostCapability::PreToolGuardrail)['mechanism'],
        );
        self::assertSame(
            'Claude project settings granular hook registrations + repository-local command hooks',
            HostCapabilityMatrix::describe('claude', HostCapability::SessionBootstrap)['mechanism'],
        );
        self::assertSame(
            '.cursor/hooks.json beforeShellExecution fail-closed shell authority policy',
            HostCapabilityMatrix::describe('cursor', HostCapability::PolicyProjection)['mechanism'],
        );
        self::assertSame(
            'Cursor .cursor/hooks.json beforeShellExecution + repository-local fail-closed shell authority guard',
            HostCapabilityMatrix::describe('cursor', HostCapability::PreToolGuardrail)['mechanism'],
        );
    }

    public function testUnknownCanonicalAgentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown canonical agent: imaginary-agent');

        HostCapabilityMatrix::forAgent('imaginary-agent');
    }
}
