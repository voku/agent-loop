<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Decision;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Decision\DecisionRouteCommand;
use voku\AgentDecision\DecisionModel;
use voku\AgentDecision\DecisionChoiceResult;
use voku\AgentDecision\Provider\SystemOneChoiceHttpClient;
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
        self::assertSame('invoking_host', $receipt['selection_authority']);
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

    public function testPrivateProjectSubagentCannotBecomeRemoteCandidate(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-private-candidate-' . bin2hex(random_bytes(6));
        $directory = $root . '/resources/subagents';
        self::assertTrue(mkdir($directory, 0o700, true));
        $path = $directory . '/internal-employee-specialist.md';
        file_put_contents(
            $path,
            "---\nname: internal-employee-specialist\ndescription: Never transmit this private workplace role.\n---\nPrivate instruction.\n",
        );

        try {
            ob_start();
            $exit = (new DecisionRouteCommand($root))->run([
                'route',
                '--mode=rules',
                '--candidate=internal-employee-specialist',
            ]);
            $output = (string) ob_get_clean();
            self::assertSame(1, $exit);
            self::assertSame('', $output);

            ob_start();
            $exit = (new DecisionRouteCommand($root))->run([
                'route',
                '--mode=rules',
            ]);
            $output = (string) ob_get_clean();
            self::assertSame(0, $exit);
            self::assertStringNotContainsString('internal-employee-specialist', $output);
            self::assertStringNotContainsString('Never transmit', $output);
        } finally {
            unlink($path);
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir($root);
        }
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
                new SystemOneChoiceHttpClient($endpoint, 1000);
                self::fail('Untrusted endpoint accepted: ' . $endpoint);
            } catch (InvalidArgumentException) {
                // The trust boundary rejected the unapproved endpoint.
            }
        }
        self::assertInstanceOf(SystemOneChoiceHttpClient::class, new SystemOneChoiceHttpClient('http://127.0.0.1:8172/v1/systemone', 1000, true));
    }

    public function testInjectedDecisionModelCannotRouteUnknownOrInvalidChoices(): void
    {
        foreach ([
            ['unmanaged-role', 0.95],
            ['agent-loop-investigator', NAN],
            ['agent-loop-investigator', 1.2],
            ['agent-loop-investigator', null],
            [null, 0.5],
        ] as [$candidateId, $probability]) {
            $model = new class ($candidateId, $probability) implements DecisionModel {
                public function __construct(
                    private ?string $candidateId,
                    private ?float $probability,
                ) {
                }

                /** @param non-empty-array<string, non-empty-string> $criteria */
                public function decide(string $publicState, array $criteria, ?string $model): DecisionChoiceResult
                {
                    return new DecisionChoiceResult($this->candidateId, $this->probability);
                }
            };

            ob_start();
            $exit = (new DecisionRouteCommand(dirname(__DIR__, 2), $model))->run([
                'route',
                '--mode=assist',
                '--data-classification=public',
                '--public-state=A publicly classified specialist routing fixture',
                '--candidate=agent-loop-investigator',
                '--endpoint=https://example.org/v1/systemone',
                '--format=json',
            ]);
            $raw = (string) ob_get_clean();
            self::assertSame(0, $exit, $raw);
            $receipt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('invalid_response', $receipt['disposition'] ?? null);
            self::assertSame('response_schema', $receipt['provider_error'] ?? null);
            self::assertNull($receipt['routing_hint'] ?? null);
            self::assertFalse($receipt['lifecycle_mutation'] ?? true);
        }
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
