<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

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
     *     evidence: 'adapter-declared'|'adapter-declared;live-runtime-unverified'|'no-agent-loop-projector'
     * }>
     */
    public static function forAgent(string $canonicalAgent): array
    {
        self::assertCanonicalAgent($canonicalAgent);

        $rows = [];
        foreach (HostCapability::cases() as $capability) {
            $description = self::describe($canonicalAgent, $capability);
            $rows[] = [
                'capability' => $capability,
                'status' => $description['status'],
                'mechanism' => $description['mechanism'],
                'evidence' => $description['evidence'],
            ];
        }

        return $rows;
    }

    public static function status(string $canonicalAgent, HostCapability $capability): HostCapabilityStatus
    {
        return self::describe($canonicalAgent, $capability)['status'];
    }

    /**
     * @return array{
     *     status: HostCapabilityStatus,
     *     mechanism: non-empty-string,
     *     evidence: 'adapter-declared'|'adapter-declared;live-runtime-unverified'|'no-agent-loop-projector'
     * }
     */
    public static function describe(string $canonicalAgent, HostCapability $capability): array
    {
        self::assertCanonicalAgent($canonicalAgent);

        if (in_array($capability, [HostCapability::SkillProjection, HostCapability::SubagentProjection], true)) {
            return [
                'status' => HostCapabilityStatus::Supported,
                'mechanism' => self::projectionMechanism($canonicalAgent, $capability),
                'evidence' => 'adapter-declared',
            ];
        }

        if (in_array($canonicalAgent, ['codex', 'claude'], true)) {
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
                'copilot' => 'SKILL.md -> GitHub Copilot skills directory',
                'gemini' => 'SKILL.md -> Gemini CLI skills directory',
                'antigravity' => 'SKILL.md -> Antigravity skills directory',
                default => throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent),
            },
            HostCapability::SubagentProjection => match ($canonicalAgent) {
                'codex' => 'canonical subagent -> Codex TOML agent definition',
                'claude' => 'canonical subagent -> Claude Markdown agent definition',
                'copilot' => 'canonical subagent -> GitHub Copilot .agent.md definition',
                'gemini' => 'canonical subagent -> Gemini CLI Markdown agent definition',
                'antigravity' => 'canonical subagent -> Antigravity Markdown agent definition',
                default => throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent),
            },
            default => throw new InvalidArgumentException('Capability has no projection mechanism: ' . $capability->value),
        };
    }

    /** @return non-empty-string */
    private static function hookMechanism(string $canonicalAgent): string
    {
        return match ($canonicalAgent) {
            'codex' => 'Codex hooks.json + repository-local command hooks',
            'claude' => 'Claude settings.json#hooks + repository-local command hooks',
            default => throw new InvalidArgumentException('No hook projector for canonical agent: ' . $canonicalAgent),
        };
    }

    private static function assertCanonicalAgent(string $canonicalAgent): void
    {
        if (!in_array($canonicalAgent, InitAgent::canonicalNames(), true)) {
            throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent);
        }
    }
}
