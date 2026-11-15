<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Decision;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Decision\DecisionRouteCommand;
use voku\AgentLoop\Decision\LayaRoutingClient;
use InvalidArgumentException;

final class DecisionRouteTest extends TestCase
{
    /** @param list<string> $args
     * @return array<string, mixed>
     */
    private function receipt(array $args): array
    {
        ob_start();
        $exit = (new DecisionRouteCommand(dirname(__DIR__, 2)))->run(['route', ...$args]);
        $raw = (string) ob_get_clean();
        self::assertSame(0, $exit, $raw);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        return $decoded;
    }

    public function testDefaultIsOfflineAndDoesNotNeedAProvider(): void
    {
        $receipt = $this->receipt([]);
        self::assertSame('off', $receipt['mode']);
        self::assertSame('not_enabled', $receipt['disposition']);
        self::assertNull($receipt['provider_latency_ms']);
        self::assertNull($receipt['routing_hint']);
        self::assertSame([], $receipt['candidate_ids']);
        self::assertFalse($receipt['lifecycle_mutation']);
    }

    public function testRulesModeRetainsHostBaselineWithoutInventingAChoice(): void
    {
        $receipt = $this->receipt(['--mode=rules']);
        self::assertSame('abstain', $receipt['disposition']);
        self::assertContains('agent-loop-investigator', $receipt['candidate_ids']);
        self::assertNull($receipt['routing_hint']);
        self::assertSame('codex_host', $receipt['selection_authority']);
    }

    public function testDuplicateCandidateFailsBeforeRemoteRequest(): void
    {
        $this->assertFails([
            '--mode=rules',
            '--candidate=agent-loop-investigator',
            '--candidate=agent-loop-investigator',
        ]);
    }

    public function testUnknownCandidateFailsBeforeRemoteRequest(): void
    {
        $this->assertFails(['--mode=rules', '--candidate=not-real']);
    }

    public function testRemoteRoutingRejectsUnclassifiedInput(): void
    {
        $this->assertFails([
            '--mode=assist',
            '--public-state=Even public-looking text is not classified automatically',
            '--endpoint=https://example.org/v1/systemone',
        ]);
    }

    public function testTaskRevisionMustBeValidatedAgainstRealApprovedContract(): void
    {
        $this->assertFails(['--mode=rules', '--task-id=NOT-REAL-654321', '--contract-revision=1']);
    }

    public function testDuplicateOptionsAndUnknownFlagsFailClosed(): void
    {
        $this->assertFails(['--mode=off', '--mode=assist']);
        $this->assertFails(['--unexpected-option=1']);
    }

    public function testEndpointTrustValidation(): void
    {
        foreach ([
            'http://example.org/v1/systemone',
            'https://127.0.0.1/v1/systemone',
            'http://127.0.0.1:8172/v1/systemone',
            'https://example.org/unsafe',
            'https://user:password@example.org/v1/systemone',
            'https://example.org/v1/systemone?secret=abc',
        ] as $endpoint) {
            try {
                new LayaRoutingClient($endpoint, 1000);
                self::fail('Untrusted endpoint accepted: ' . $endpoint);
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertInstanceOf(LayaRoutingClient::class, new LayaRoutingClient('http://127.0.0.1:8172/v1/systemone', 1000, true));
    }

    /** @param list<string> $args */
    private function assertFails(array $args): void
    {
        ob_start();
        $exit = (new DecisionRouteCommand(dirname(__DIR__, 2)))->run(['route', ...$args]);
        $output = (string) ob_get_clean();
        self::assertSame(1, $exit, $output);
        self::assertSame('', $output);
    }
}
