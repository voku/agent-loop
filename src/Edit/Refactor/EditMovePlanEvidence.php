<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use voku\AgentMap\Index\AgentMapIndex;

/** Normalized host view of one safe Map plan made only of exact edits and optional file moves. */
interface EditMovePlanEvidence
{
    /** Returns the stable owner-published plan type. */
    public function planType(): string;

    /** Returns the exact target identity bound by the plan. */
    public function targetId(): string;

    /** Reports whether applying this plan requires PHPStan-backed Map evidence. */
    public function requiresPhpStan(): bool;

    /** @return list<RenamePlanEditEvidence> */
    public function edits(): array;

    /** @return list<RenamePlanMoveEvidence> */
    public function moves(): array;

    /** Revalidates frozen plan provenance against the current Map snapshot. */
    public function assertMatches(AgentMapIndex $map): void;
}
