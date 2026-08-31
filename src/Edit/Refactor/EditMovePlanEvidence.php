<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use voku\AgentMap\Index\AgentMapIndex;

/** Normalized host view of one safe Map plan made only of exact edits and optional file moves. */
interface EditMovePlanEvidence
{
    public function planType(): string;

    /** @return list<RenamePlanEditEvidence> */
    public function edits(): array;

    /** @return list<RenamePlanMoveEvidence> */
    public function moves(): array;

    public function assertMatches(AgentMapIndex $map): void;
}
