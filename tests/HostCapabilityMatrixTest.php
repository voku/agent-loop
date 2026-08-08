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
            self::assertSame(HostCapabilityStatus::Supported, HostCapabilityMatrix::status($agent, HostCapability::Skills));
            self::assertSame(HostCapabilityStatus::Supported, HostCapabilityMatrix::status($agent, HostCapability::Subagents));
        }
    }

    public function testHookBackedDisciplineIsOnlyImplementedForCodexAndClaude(): void
    {
        $hookBacked = [
            HostCapability::SessionBootstrap,
            HostCapability::SubagentBootstrap,
            HostCapability::PreToolGuardrail,
            HostCapability::RepositoryHooks,
        ];

        foreach ($hookBacked as $capability) {
            self::assertSame(HostCapabilityStatus::Supported, HostCapabilityMatrix::status('codex', $capability));
            self::assertSame(HostCapabilityStatus::Supported, HostCapabilityMatrix::status('claude', $capability));
            self::assertSame(HostCapabilityStatus::Unsupported, HostCapabilityMatrix::status('copilot', $capability));
            self::assertSame(HostCapabilityStatus::Unsupported, HostCapabilityMatrix::status('antigravity', $capability));
        }
    }

    public function testUnknownCanonicalAgentIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown canonical agent: imaginary-agent');

        HostCapabilityMatrix::forAgent('imaginary-agent');
    }
}
