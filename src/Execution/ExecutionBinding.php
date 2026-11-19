<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

/**
 * The identity of Loop's current governed execution: the non-retired Run of
 * the approved Contract revision and the digest of its persisted plan.
 *
 * Observers (dashboards, Runner journal readers) compare their own records
 * with this identity instead of rebuilding the binding rules from stores. It
 * is an observation only and grants no execution authority.
 */
final readonly class ExecutionBinding
{
    public function __construct(
        public string $runId,
        public int $contractRevision,
        public string $executionPlanDigest,
    ) {
    }
}
