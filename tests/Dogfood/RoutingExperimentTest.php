<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Dogfood;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\Routing\RoutingExperimentFixture;
use voku\AgentLoop\Dogfood\Routing\RoutingExperimentReceipt;
use voku\AgentLoop\Dogfood\Routing\RoutingHint;

final class RoutingExperimentTest extends TestCase
{
    private string $rootPath;

    protected function setUp(): void
    {
        $this->rootPath = dirname(__DIR__, 2);
    }

    public function testManagedCandidatesAreCanonicalAndSorted(): void
    {
        $fixture = RoutingExperimentFixture::baseline($this->rootPath, 'routing-001', 'Locate the owner.');

        $candidateIds = $fixture->candidateIds();
        $sorted = $candidateIds;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $candidateIds);
        self::assertContains('agent-loop-investigator', $candidateIds);
        self::assertContains('agent-loop-code-reviewer', $candidateIds);
    }

    public function testUnknownHintCandidateIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('managed subagent candidates');

        RoutingExperimentFixture::hinted(
            $this->rootPath,
            'routing-001',
            'Locate the owner.',
            'invented-specialist',
            0.9,
        );
    }

    public function testProbabilityBoundsAreEnforced(): void
    {
        $fixture = RoutingExperimentFixture::baseline($this->rootPath, 'routing-001', 'Locate the owner.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('between 0.0 and 1.0');

        new RoutingHint('agent-loop-investigator', 1.01, $fixture->candidateIds());
    }

    public function testBaselinePromptContainsNoRoutingHint(): void
    {
        $fixture = RoutingExperimentFixture::baseline($this->rootPath, 'routing-001', 'Locate the owner.');

        self::assertNull($fixture->hint);
        self::assertSame('baseline', $fixture->arm());
        self::assertSame('Locate the owner.', $fixture->renderParentPrompt());
    }

    public function testHintedPromptIsBoundedAndAdvisory(): void
    {
        $fixture = RoutingExperimentFixture::hinted(
            $this->rootPath,
            'routing-001',
            'Locate the owner.',
            'agent-loop-investigator',
            0.82,
        );

        $prompt = $fixture->renderParentPrompt();

        self::assertSame('hinted', $fixture->arm());
        self::assertStringContainsString('<agent-loop-routing-hint>', $prompt);
        self::assertStringContainsString('"candidate_id":"agent-loop-investigator"', $prompt);
        self::assertStringContainsString('"probability":0.82', $prompt);
        self::assertStringContainsString('"authority":"advisory"', $prompt);
        self::assertStringContainsString('"host_retains_final_selection":true', $prompt);
        foreach (['next_action', 'mutation_ready', 'approval', 'learning_disposition', 'workflow_close'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $prompt);
        }
    }

    public function testReceiptRejectsUnknownObservedSelection(): void
    {
        $fixture = RoutingExperimentFixture::baseline($this->rootPath, 'routing-001', 'Locate the owner.');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside the fixture candidate set');

        RoutingExperimentReceipt::observe(
            $fixture,
            '0123456789abcdef0123456789abcdef01234567',
            'invented-specialist',
            true,
            true,
            true,
        );
    }

    public function testReceiptContainsOnlyObservableRoutingFacts(): void
    {
        $fixture = RoutingExperimentFixture::hinted(
            $this->rootPath,
            'routing-001',
            'Private prompt text must not be copied into the receipt.',
            'agent-loop-investigator',
            0.82,
        );

        $receipt = RoutingExperimentReceipt::observe(
            $fixture,
            '0123456789abcdef0123456789abcdef01234567',
            'agent-loop-investigator',
            true,
            true,
            true,
        )->toArray();

        self::assertSame('1.0', $receipt['schema_version']);
        self::assertSame('hinted', $receipt['arm']);
        self::assertSame(
            ['candidate_id' => 'agent-loop-investigator', 'probability' => 0.82],
            $receipt['hint'],
        );
        self::assertTrue($receipt['spawn_observed']);
        self::assertTrue($receipt['child_started']);
        self::assertTrue($receipt['wait_completed']);
        self::assertStringNotContainsString('Private prompt text', json_encode($receipt, JSON_THROW_ON_ERROR));
    }
}
