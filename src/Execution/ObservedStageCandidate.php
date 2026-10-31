<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

/** The candidate a completing mutating-stage submission carries, plus whether the shared checkout holds other work. */
final readonly class ObservedStageCandidate
{
    public function __construct(
        public string $candidateRevision,
        public bool $changesOutsideScope = false,
    ) {
    }
}
