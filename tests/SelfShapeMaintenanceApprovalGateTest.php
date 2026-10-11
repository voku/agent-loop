<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\AppliedGuidanceMaintenanceEvidence;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceApprovalGate;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceReviewClaim;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceReviewCommentEvidence;

/** @internal */
final class SelfShapeMaintenanceApprovalGateTest extends TestCase
{
    private const string BASE = '622c004e2cc642fce1c06dc63a4f04d4889637e2';
    private const string HEAD = 'f1d1f7b91a7adad974aa88ca5104135894d43196';
    private const string BEFORE = '0f6fc7f561e435f8e9e3e53cf3fc8cc284175ed80b38211880271282c911564a';
    private const string AFTER = '7a9afab8ec812280ae036e6afd901d6c8d7e0f35bc3439197a547f886926fa0e';

    public function testAcceptsExactMaintenanceOnlyWithTheTwoAppliedProofFiles(): void
    {
        self::assertTrue($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            $this->expectedPaths(),
        ));
    }

    public function testExtraProductionChangeIsNotDisguisedAsMemoryMaintenance(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            [...$this->expectedPaths(), 'src/Dispatcher.php'],
        ));
    }

    public function testMissingAppliedProofChangeCannotPass(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            ['MEMORY.md', '.agent-loop/learning/proposals/applied/proposal.2026-08-14.007.json'],
        ));
    }

    public function testStaleOrTamperedFullDiffIsNotApproved(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'other diff'),
            $this->expectedPaths(),
        ));
    }

    public function testDuplicateOrForeignChangedPathIsRejected(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            [...$this->expectedPaths(), 'MEMORY.md'],
        ));
    }

    private function gate(): SelfShapeMaintenanceApprovalGate
    {
        return new SelfShapeMaintenanceApprovalGate();
    }

    private function review(): SelfShapeMaintenanceReviewCommentEvidence
    {
        return new SelfShapeMaintenanceReviewCommentEvidence(
            new SelfShapeMaintenanceReviewClaim(
                'MEMORY.md', self::BASE, self::HEAD, self::BEFORE, self::AFTER, hash('sha256', 'complete diff'),
            ),
            'voku',
            123456,
            'https://github.com/voku/agent-loop/pull/758#issuecomment-123456',
        );
    }

    private function owner(): AppliedGuidanceMaintenanceEvidence
    {
        return new AppliedGuidanceMaintenanceEvidence(
            'MEMORY.md',
            self::BEFORE,
            self::AFTER,
            ['proposal.2026-08-14.007', 'proposal.2026-08-14.013'],
            [
                'proposal.2026-08-14.007' => [
                    'actor' => 'voku',
                    'at' => '2026-10-10T19:33:03+00:00',
                    'reason' => 'correct canonical home',
                ],
                'proposal.2026-08-14.013' => [
                    'actor' => 'voku',
                    'at' => '2026-10-10T19:33:03+00:00',
                    'reason' => 'correct canonical home',
                ],
            ],
        );
    }

    /** @return list<string> */
    private function expectedPaths(): array
    {
        return [
            '.agent-loop/learning/proposals/applied/proposal.2026-08-14.007.json',
            '.agent-loop/learning/proposals/applied/proposal.2026-08-14.013.json',
            'MEMORY.md',
        ];
    }
}
