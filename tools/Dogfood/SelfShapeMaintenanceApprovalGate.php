<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use voku\AgentLearning\AppliedGuidanceMaintenanceEvidence;

/**
 * Final fail-closed decision over separately authenticated GitHub review
 * attribution and owner-validated physical proof.
 */
final readonly class SelfShapeMaintenanceApprovalGate
{
    public function __construct(
        private SelfShapeMaintenanceReviewMatcher $matcher = new SelfShapeMaintenanceReviewMatcher(),
    ) {
    }

    /**
     * @param list<string> $changedFiles Entire exact Git base..PR-head file list
     */
    public function matches(
        AppliedGuidanceMaintenanceEvidence $owner,
        SelfShapeMaintenanceReviewCommentEvidence $review,
        string $baseCommit,
        string $headCommit,
        string $completeDiffSha256,
        array $changedFiles,
    ): bool {
        $expectedPaths = ['MEMORY.md'];
        foreach ($owner->proposalIds as $id) {
            $expectedPaths[] = '.agent-loop/learning/proposals/applied/' . $id . '.json';
        }

        sort($expectedPaths);
        sort($changedFiles);
        if ($expectedPaths !== $changedFiles) {
            return false;
        }

        return $this->matcher->matches(
            $owner,
            $review->claim,
            $baseCommit,
            $headCommit,
            $completeDiffSha256,
        );
    }
}
