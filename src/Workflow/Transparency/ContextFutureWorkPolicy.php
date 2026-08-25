<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use voku\AgentLoop\FutureWorkMode;

/**
 * The repository's follow-up boundary.
 *
 * This is policy, not a record that anything was deferred. A host that wants to
 * say "deferred" needs a durable owner artifact naming the follow-up.
 */
final readonly class ContextFutureWorkPolicy
{
    public function __construct(public FutureWorkMode $mode, public int $maxFollowUpSlices)
    {
    }

    /**
     * @return array{
     *     mode: 'focus'|'discover'|'invest',
     *     max_follow_up_slices: int,
     *     current_contract_scope_expansion: 'forbidden',
     *     follow_up_authority: 'separate_contract_required'
     * }
     */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode->value,
            'max_follow_up_slices' => $this->maxFollowUpSlices,
            'current_contract_scope_expansion' => 'forbidden',
            'follow_up_authority' => 'separate_contract_required',
        ];
    }
}
