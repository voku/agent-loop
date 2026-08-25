<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * What a planned managed-asset operation would do to one entry.
 *
 * BLOCKED is a first-class outcome rather than an omission: a plan that
 * silently drops an entry it refuses to touch is indistinguishable from a plan
 * that never considered it, and a human approving a removal needs to see what
 * will survive and why.
 */
enum ManagedAssetOperationKind: string
{
    case ADD = 'add';
    case UPDATE = 'update';
    case REMOVE = 'remove';
    case BLOCKED = 'blocked';

    public function mutates(): bool
    {
        return $this !== self::BLOCKED;
    }
}
