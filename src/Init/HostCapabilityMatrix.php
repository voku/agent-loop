<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class HostCapabilityMatrix
{
    /**
     * @return list<array{capability: HostCapability, status: HostCapabilityStatus}>
     */
    public static function forAgent(string $canonicalAgent): array
    {
        if (!in_array($canonicalAgent, InitAgent::canonicalNames(), true)) {
            throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent);
        }

        $rows = [];
        foreach (HostCapability::cases() as $capability) {
            $rows[] = [
                'capability' => $capability,
                'status' => self::status($canonicalAgent, $capability),
            ];
        }

        return $rows;
    }

    public static function status(string $canonicalAgent, HostCapability $capability): HostCapabilityStatus
    {
        if (!in_array($canonicalAgent, InitAgent::canonicalNames(), true)) {
            throw new InvalidArgumentException('Unknown canonical agent: ' . $canonicalAgent);
        }

        return match ($capability) {
            HostCapability::SkillProjection,
            HostCapability::SubagentProjection => HostCapabilityStatus::Supported,
            HostCapability::SessionBootstrap,
            HostCapability::SubagentBootstrap,
            HostCapability::PreToolGuardrail,
            HostCapability::RepositoryHooks => in_array($canonicalAgent, ['codex', 'claude'], true)
                ? HostCapabilityStatus::Degraded
                : HostCapabilityStatus::Unsupported,
        };
    }
}
