<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * Whether the persisted review report describes the implementation that exists now.
 */
enum ReviewCurrency: string
{
    case MISSING = 'missing';
    case INVALID = 'invalid';
    case CURRENT = 'current';
    case STALE = 'stale';

    /**
     * The report exists and parses, but no approved Contract / matching Run is
     * available to bind it against. Its findings are readable evidence; their
     * currency is simply unknown.
     */
    case UNBOUND = 'unbound';
}
