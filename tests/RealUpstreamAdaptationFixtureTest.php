<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class RealUpstreamAdaptationFixtureTest extends TestCase
{
    public function testPullRequest33KeepsPinnedSelectiveDecisions(): void
    {
        $fixture = file_get_contents(__DIR__ . '/fixtures/real-upstream-adaptation/agent-loop-33.json');
        $matrix = file_get_contents(__DIR__ . '/../docs/agents/UPSTREAM_CAPABILITY_MATRIX.md');
        self::assertIsString($fixture);
        self::assertIsString($matrix);

        foreach (['14d4f2e21a16b573373ca24698cd6bd3db75bf52', '2ed6c52c9d7e5e56942508591085fd45dea277d3', '3c8a2a8a38f163aa85ad325812b5ce3ba330ad27'] as $sha) {
            self::assertStringContainsString($sha, $fixture);
            self::assertStringContainsString($sha, $matrix);
        }
        foreach (['ALREADY', 'ADAPT', 'DEFER', 'REJECT'] as $decision) {
            self::assertStringContainsString('"decision": "' . $decision . '"', $fixture);
            self::assertStringContainsString('`' . $decision . '`', $matrix);
        }
    }
}
