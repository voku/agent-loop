<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Describes a claimed, exact-diff human verdict from an external authority.
 *
 * It does not authenticate its own source and must never be treated as a
 * standalone approval. It is only useful after independently validating its
 * origin, reviewer permissions, and the GitHub review commit.
 */
final readonly class SelfShapeMaintenanceReviewClaim
{
    public function __construct(
        public string $source,
        public string $baseCommit,
        public string $headCommit,
        public string $beforeSha256,
        public string $afterSha256,
        public string $diffSha256,
    ) {
    }
}
