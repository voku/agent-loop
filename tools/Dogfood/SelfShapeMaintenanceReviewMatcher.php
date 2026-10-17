<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use voku\AgentLearning\AppliedGuidanceMaintenanceEvidence;

/**
 * Compares already owner-validated physical maintenance evidence to the exact
 * diff a human reviewer has explicitly classified as maintenance.
 *
 * This is deliberately only a match, NOT proof that the review came from a
 * human. The caller must authenticate and authorize the review externally,
 * e.g. with GitHub's review API against the exact PR head.
 */
final readonly class SelfShapeMaintenanceReviewMatcher
{
    public function matches(
        AppliedGuidanceMaintenanceEvidence $owner,
        SelfShapeMaintenanceReviewClaim $review,
        string $baseCommit,
        string $headCommit,
        string $diffSha256,
    ): bool {
        if ($review->source !== 'MEMORY.md'
            || $owner->targetSourceRef !== $review->source
            || $review->baseCommit !== $baseCommit
            || $review->headCommit !== $headCommit
            || !hash_equals($owner->beforeSha256, $review->beforeSha256)
            || !hash_equals($owner->afterSha256, $review->afterSha256)
            || !hash_equals($review->diffSha256, $diffSha256)
            || count($owner->proposalIds) !== count($owner->reanchors)
        ) {
            return false;
        }

        foreach ($owner->proposalIds as $id) {
            if (!isset($owner->reanchors[$id])) {
                return false;
            }
        }

        return true;
    }
}
