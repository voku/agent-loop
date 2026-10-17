<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * GitHub-attributed review statement from a separately authenticated API read.
 *
 * Even with an account permission check, this does not prove which human
 * controlled the account or establish the correctness of the claimed verdict.
 */
final readonly class SelfShapeMaintenanceReviewCommentEvidence
{
    public function __construct(
        public SelfShapeMaintenanceReviewClaim $claim,
        public string $githubLogin,
        public int $commentId,
        public string $commentUrl,
    ) {
    }
}
