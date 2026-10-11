<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLearning\AppliedGuidanceMaintenanceEvidence;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceReviewClaim;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceReviewMatcher;

/** @internal */
final class SelfShapeMaintenanceReviewMatcherTest extends TestCase
{
    private const string BASE = '622c004e2cc642fce1c06dc63a4f04d4889637e2';
    private const string HEAD = 'f1d1f7b91a7adad974aa88ca5104135894d43196';
    private const string BEFORE = '0f6fc7f561e435f8e9e3e53cf3fc8cc284175ed80b38211880271282c911564a';
    private const string AFTER = '7a9afab8ec812280ae036e6afd901d6c8d7e0f35bc3439197a547f886926fa0e';

    public function testOwnerProofAndExactReviewClaimMatchActual758Identity(): void
    {
        self::assertTrue((new SelfShapeMaintenanceReviewMatcher())->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'frozen-758-diff'),
        ));
    }

    public function testNoReviewDoesNotBypassSelfShapeFindingGate(): void
    {
        $evidence = new \voku\AgentLoop\Dogfood\SelfShapeEvidence(['MEMORY.md'], true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a durable rule needs evidence');
        $evidence->learningStatus();
    }

    public function testChangedHeadIsNotReviewed(): void
    {
        self::assertFalse((new SelfShapeMaintenanceReviewMatcher())->matches(
            $this->owner(), $this->review(), self::BASE, str_repeat('a', 40), hash('sha256', 'frozen-758-diff'),
        ));
    }

    public function testChangedDiffIsNotReviewed(): void
    {
        self::assertFalse((new SelfShapeMaintenanceReviewMatcher())->matches(
            $this->owner(), $this->review(), self::BASE, self::HEAD, hash('sha256', 'different-diff'),
        ));
    }

    public function testChangedTargetOrHashesAreNotReviewed(): void
    {
        $owner = new AppliedGuidanceMaintenanceEvidence(
            'docs/WRONG.md', self::BEFORE, self::AFTER,
            ['proposal.2026-08-14.007'],
            ['proposal.2026-08-14.007' => ['actor' => 'maintainer', 'at' => '2026-10-10T20:00:00+00:00', 'reason' => 'repair']],
        );
        self::assertFalse((new SelfShapeMaintenanceReviewMatcher())->matches(
            $owner, $this->review(), self::BASE, self::HEAD, hash('sha256', 'frozen-758-diff'),
        ));
        self::assertFalse((new SelfShapeMaintenanceReviewMatcher())->matches(
            $this->owner(), new SelfShapeMaintenanceReviewClaim('MEMORY.md', self::BASE, self::HEAD, str_repeat('b', 64), self::AFTER, hash('sha256', 'frozen-758-diff')),
            self::BASE, self::HEAD, hash('sha256', 'frozen-758-diff'),
        ));
    }

    public function testMissingAppliedProofIsNotReviewed(): void
    {
        $owner = new AppliedGuidanceMaintenanceEvidence(
            'MEMORY.md', self::BEFORE, self::AFTER, ['proposal.2026-08-14.007'],
            ['proposal.2026-08-14.013' => ['actor' => 'maintainer', 'at' => '2026-10-10T20:00:00+00:00', 'reason' => 'other proposal']],
        );
        self::assertFalse((new SelfShapeMaintenanceReviewMatcher())->matches(
            $owner, $this->review(), self::BASE, self::HEAD, hash('sha256', 'frozen-758-diff'),
        ));
    }

    private function review(): SelfShapeMaintenanceReviewClaim
    {
        return new SelfShapeMaintenanceReviewClaim(
            'MEMORY.md', self::BASE, self::HEAD, self::BEFORE, self::AFTER, hash('sha256', 'frozen-758-diff'),
        );
    }

    private function owner(): AppliedGuidanceMaintenanceEvidence
    {
        return new AppliedGuidanceMaintenanceEvidence(
            'MEMORY.md', self::BEFORE, self::AFTER,
            ['proposal.2026-08-14.007', 'proposal.2026-08-14.013'],
            [
                'proposal.2026-08-14.007' => ['actor' => 'maintainer', 'at' => '2026-10-10T20:00:00+00:00', 'reason' => 'path correction'],
                'proposal.2026-08-14.013' => ['actor' => 'maintainer', 'at' => '2026-10-10T20:00:00+00:00', 'reason' => 'path correction'],
            ],
        );
    }
}
