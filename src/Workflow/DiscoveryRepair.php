<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

/**
 * The repair that must run before governed preparation can be approved.
 *
 * Produced by the discovery-readiness owner so the lifecycle result can name
 * an action that is actually executable in the current state. Consumers must
 * not re-derive it: reconstructing "map missing implies map build" outside the
 * owner is the duplication the Recall boundary work removed.
 */
final readonly class DiscoveryRepair
{
    public function __construct(
        public string $reason,
        public string $nextAction,
    ) {
    }
}
