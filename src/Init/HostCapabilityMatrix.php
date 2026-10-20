<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Static catalog of capabilities implemented by agent-loop host adapters.
 *
 * This class intentionally says nothing about whether a host executable is
 * installed in the current environment or whether a lifecycle hook has run.
 * Runtime discovery belongs to HostRuntimeProbe; live lifecycle evidence must
 * come from an actual host execution.
 */
final readonly class HostCapabilityMatrix
{
    /**
     * @return list<array{
     *     capability: HostCapability,
     *     status: HostCapabilityStatus,
     *     mechanism: non-empty-string,
     *     runtime_evidence?: HostRuntimeEvidence,
     *     evidence: 'adapter-declared'|'adapter-declared;live-runtime-verified'|'adapter-declared;live-runtime-unverified'|'adapter-declared;live-runtime-refuted'|'no-agent-loop-projector'
     * }>
     */
    public static function forAgent(string $canonicalAgent, ?string $runtimeVersion = null): array
    {
        self::assertCanonicalAgent($canonicalAgent);

        $rows = [];
        foreach (HostCapability::cases() as $capability) {
            $description = self::describe($canonicalAgent, $capability, $runtimeVersion);
            $rows[] = [
                'capability' => $capability,
                'status' => $description['status'],
                'mechanism' => $description['mechanism'],
                'evidence' => $description['evidence'],
                ...isset($description['runtime_evidence']) ? ['runtime_evidence' => $description['runtime_evidence']] : [],
            ];
        }

        return $rows;
    }

    public static function status(
        string $canonicalAgent,
        HostCapability $capability,
        ?string $runtimeVersion = null,
    ): HostCapabilityStatus {
        return self::describe($canonicalAgent, $capability, $runtimeVersion)['status'];
    }

    /**
     * @return array{
     *     status: HostCapabilityStatus,
     *     mechanism: non-empty-string,
     *     runtime_evidence?: HostRuntimeEvidence,
     *     evidence: 'adapter-declared'|'adapter-declared;live-runtime-verified'|'adapter-declared;live-runtime-unverified'|'adapter-declared;live-runtime-refuted'|'no-agent-loop-projector'
     * }
     */
    public static function describe(
        string $canonicalAgent,
        HostCapability $capability,
        ?string $runtimeVersion = null,
    ): array {
        self::assertCanonicalAgent($canonicalAgent);

        if (in_array($capability, [HostCapability::SkillProjection, HostCapability::SubagentProjection], true)) {
            return [
                'status' => HostCapabilityStatus::Supported,
                'mechanism' => self::projectionMechanism($canonicalAgent, $capability),
                'evidence' => 'adapter-declared',
            ];
        }

        if ($capability === HostCapability::SubagentReadOnlyEnforcement) {
            if ($canonicalAgent === 'codex') {
                $runtimeEvidence = self::runtimeEvidence('codex', $capability);

                return [
                    'status' => HostCapabilityStatus::Degraded,
                    'runtime_evidence' => $runtimeEvidence,
                    'mechanism' => 'canonical subagent mutation: read-only -> Codex sandbox_mode = read-only; Codex 0.157.0 selected-child runtime proof observed no authority downgrade',
                    'evidence' => $runtimeEvidence->classification($runtimeVersion) === 'exact_verified'
                        ? 'adapter-declared;live-runtime-refuted'
                        : 'adapter-declared;live-runtime-unverified',
                ];
            }

            if ($canonicalAgent === 'cursor') {
                return [
                    'status' => HostCapabilityStatus::Supported,
                    'mechanism' => 'canonical subagent mutation: read-only -> Cursor readonly = true',
                    'evidence' => 'adapter-declared',
                ];
            }

            return [
                'status' => HostCapabilityStatus::Unsupported,
                'mechanism' => 'no agent-loop read-only subagent enforcement projector',
                'evidence' => 'no-agent-loop-projector',
            ];
        }

        if ($capability === HostCapability::PolicyProjection) {
            if (in_array($canonicalAgent, ['codex', 'claude', 'opencode', 'cursor'], true)) {
                return [
                    'status' => HostCapabilityStatus::Supported,
                    'mechanism' => self::policyMechanism($canonicalAgent),
                    'evidence' => 'adapter-declared',
                ];
            }

            return [
                'status' => HostCapabilityStatus::Unsupported,
                'mechanism' => 'no agent-loop host policy projector',
                'evidence' => 'no-agent-loop-projector',
            ];
        }

        if ($canonicalAgent === 'codex' && in_array($capability, [
            HostCapability::SessionBootstrap,
            HostCapability::SubagentBootstrap,
            HostCapability::PreToolGuardrail,
            HostCapability::RepositoryHooks,
        ], true)) {
            $runtimeEvidence = self::runtimeEvidence('codex', $capability);
            $testedVersion = $runtimeEvidence->hostVersion;
            $verified = $runtimeEvidence->classification($runtimeVersion) === 'exact_verified';

            return [
                'status' => $verified ? HostCapabilityStatus::Supported : HostCapabilityStatus::Degraded,
                'mechanism' => self::runtimeVersionMechanism(
                    self::codexVerifiedHookMechanism($capability),
                    $testedVersion,
                    $runtimeVersion,
                    $verified,
                ),
                'runtime_evidence' => $runtimeEvidence,
                'evidence' => $verified
                    ? 'adapter-declared;live-runtime-verified'
                    : 'adapter-declared;live-runtime-unverified',
            ];
        }

        if ($canonicalAgent === 'claude' && $capability === HostCapability::PreToolGuardrail) {
            $runtimeEvidence = self::runtimeEvidence('claude', $capability);
            $testedVersion = $runtimeEvidence->hostVersion;
            $verified = $runtimeEvidence->classification($runtimeVersion) === 'exact_verified';

            return [
                'status' => $verified ? HostCapabilityStatus::Supported : HostCapabilityStatus::Degraded,
                'mechanism' => self::runtimeVersionMechanism(
                    'Claude project settings PreToolUse ^Bash$ command hook; Claude 2.1.287 runtime proof observed bounded alternate-publication deny enforcement under bypassPermissions',
                    $testedVersion,
                    $runtimeVersion,
                    $verified,
                ),
                'runtime_evidence' => $runtimeEvidence,
                'evidence' => $verified
                    ? 'adapter-declared;live-runtime-verified'
                    : 'adapter-declared;live-runtime-unverified',
            ];
        }

        if (in_array($canonicalAgent, ['codex', 'claude'], true)
            || ($canonicalAgent === 'cursor' && in_array($capability, [HostCapability::PreToolGuardrail, HostCapability::RepositoryHooks], true))
        ) {
            return [
                'status' => HostCapabilityStatus::Degraded,
                'mechanism' => self::hookMechanism($canonicalAgent),
                'evidence' => 'adapter-declared;live-runtime-unverified',
            ];
        }

        return [
            'status' => HostCapabilityStatus::Unsupported,
            'mechanism' => 'no agent-loop host-native projector',
            'evidence' => 'no-agent-loop-projector',
        ];
    }

    /** @return non-empty-string */
    private static function projectionMechanism(string $canonicalAgent, HostCapability $capability): string
    {
        return match ($capability) {
            HostCapability::SkillProjection => match ($canonicalAgent) {
                'codex' => 'SKILL.md -> Codex skills directory',
                'claude' => 'SKILL.md -> Claude skills directory',
                'opencode' => 'SKILL.md -> OpenCode .opencode/skills directory',
                'copilot' => 'SKILL.md -> GitHub Copilot skills directory',
                'gemini' => 'SKILL.md -> Gemini CLI skills directory',
                'antigravity' => 'SKILL.md -> Antigravity skills directory',
                'cursor' => 'SKILL.md -> Cursor .cursor/skills directory',
                default => throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent),
            },
            HostCapability::SubagentProjection => match ($canonicalAgent) {
                'codex' => 'canonical subagent -> Codex TOML agent definition',
                'claude' => 'canonical subagent -> Claude Markdown agent definition',
                'opencode' => 'canonical subagent -> OpenCode .opencode/agents Markdown definition',
                'copilot' => 'canonical subagent -> GitHub Copilot .agent.md definition',
                'gemini' => 'canonical subagent -> Gemini CLI Markdown agent definition',
                'antigravity' => 'canonical subagent -> Antigravity Markdown agent definition',
                'cursor' => 'canonical subagent -> Cursor .cursor/agents Markdown definition',
                default => throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent),
            },
            default => throw new InvalidArgumentException('Capability has no projection mechanism: ' . $capability->value),
        };
    }

    /** @return non-empty-string */
    private static function policyMechanism(string $canonicalAgent): string
    {
        return match ($canonicalAgent) {
            'codex' => '.codex/rules/agent-loop.rules forbidden direct publication command prefixes',
            'claude' => '.claude/settings.json#permissions project policy; autoMode remains user-scoped',
            'opencode' => 'opencode.json#permission project policy',
            'cursor' => '.cursor/hooks.json beforeShellExecution fail-closed shell authority policy',
            default => throw new InvalidArgumentException('No policy projector for canonical agent: ' . $canonicalAgent),
        };
    }

    /** @return non-empty-string */
    private static function codexVerifiedHookMechanism(HostCapability $capability): string
    {
        return match ($capability) {
            HostCapability::SessionBootstrap => 'Codex hooks.json SessionStart command hook; Codex 0.157.0 project-trust and persisted-hash runtime proofs observed execution',
            HostCapability::SubagentBootstrap => 'Codex hooks.json SubagentStart command hook; Codex 0.157.0 selected-child runtime proof observed execution',
            HostCapability::PreToolGuardrail => 'Codex hooks.json PreToolUse ^Bash$ command hook; Codex 0.157.0 canonical exec_command/Bash runtime proof observed deny enforcement',
            HostCapability::RepositoryHooks => 'Codex hooks.json + repository-local command hooks; Codex 0.157.0 runtime proofs observed SessionStart, SubagentStart, and PreToolUse execution',
            default => throw new InvalidArgumentException('Capability has no verified Codex hook runtime mechanism: ' . $capability->value),
        };
    }

    /** @return non-empty-string */
    private static function hookMechanism(string $canonicalAgent): string
    {
        return match ($canonicalAgent) {
            'codex' => 'Codex hooks.json + repository-local command hooks',
            'claude' => 'Claude project settings granular hook registrations + repository-local command hooks',
            'cursor' => 'Cursor .cursor/hooks.json beforeShellExecution + repository-local fail-closed shell authority guard',
            default => throw new InvalidArgumentException('No hook projector for canonical agent: ' . $canonicalAgent),
        };
    }

    /**
     * Frozen successful pinned-host proof runs; no clock refresh or network lookup.
     *
     * @param 'codex'|'claude' $host
     */
    private static function runtimeEvidence(string $host, HostCapability $capability): HostRuntimeEvidence
    {
        return new HostRuntimeEvidence(
            host: $host,
            capability: $capability,
            hostVersion: $host === 'codex' ? '0.157.0' : '2.1.287',
            verifiedAt: new DateTimeImmutable($host === 'codex' ? '2026-10-19T17:07:54Z' : '2026-10-19T17:07:49Z'),
            evidenceRef: 'https://github.com/voku/agent-loop/actions/runs/' . ($host === 'codex' ? '39291414757' : '39291414596'),
            verifiedBy: 'GitHub Actions pinned ' . $host . ' runtime proof at ac1b898d3f8cb30ea718da25aae4f1aa7f0ca8e2',
        );
    }

    /**
     * @param non-empty-string $mechanism
     * @return non-empty-string
     */
    private static function runtimeVersionMechanism(
        string $mechanism,
        string $testedVersion,
        ?string $runtimeVersion,
        bool $verified,
    ): string {
        if ($verified) {
            return $mechanism;
        }

        return $mechanism . '; live-runtime evidence unverified (tested=' . $testedVersion
            . ', current=' . ($runtimeVersion ?? 'unknown') . ')';
    }

    private static function assertCanonicalAgent(string $canonicalAgent): void
    {
        if (!in_array($canonicalAgent, InitAgent::canonicalNames(), true)) {
            throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent);
        }
    }
}
