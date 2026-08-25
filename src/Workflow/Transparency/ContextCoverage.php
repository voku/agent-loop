<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * What context construction actually included, skipped and omitted.
 *
 * Hosts read these facts instead of parsing the rendered `lines`, which is a
 * display format and has never been an API.
 */
final readonly class ContextCoverage
{
    /**
     * @param list<string> $skipped Inputs that were missing or invalid, each with the owner's reason.
     * @param list<ContextOmission> $omitted Categories dropped by the render budget, ordered by category.
     */
    public function __construct(
        public array $skipped,
        public array $omitted,
        public ContextInteractionPolicy $interaction,
        public ContextFutureWorkPolicy $futureWork,
    ) {
    }

    /**
     * @return array{
     *     skipped: list<string>,
     *     omitted: list<array{category: string, count: int}>,
     *     interaction: array{human_explanations: 'ask'|'always'|'never', interactive_behavior: 'ask'|'generate'|'skip', unattended_behavior: 'generate'|'skip', authority_bearing_decisions: 'human_required'},
     *     future_work: array{mode: 'focus'|'discover'|'invest', max_follow_up_slices: int, current_contract_scope_expansion: 'forbidden', follow_up_authority: 'separate_contract_required'}
     * }
     */
    public function toArray(): array
    {
        return [
            'skipped' => $this->skipped,
            'omitted' => array_map(static fn (ContextOmission $omission): array => $omission->toArray(), $this->omitted),
            'interaction' => $this->interaction->toArray(),
            'future_work' => $this->futureWork->toArray(),
        ];
    }
}
