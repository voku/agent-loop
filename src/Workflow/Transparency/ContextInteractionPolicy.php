<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use voku\AgentLoop\HumanExplanationPolicy;

/**
 * How this repository wants a host to interact while building context.
 *
 * Authority-bearing decisions stay human-owned regardless of these values: the
 * policy governs optional model-generated explanation work, never approval.
 */
final readonly class ContextInteractionPolicy
{
    public function __construct(public HumanExplanationPolicy $humanExplanations)
    {
    }

    /**
     * @return array{
     *     human_explanations: 'ask'|'always'|'never',
     *     interactive_behavior: 'ask'|'generate'|'skip',
     *     unattended_behavior: 'generate'|'skip',
     *     authority_bearing_decisions: 'human_required'
     * }
     */
    public function toArray(): array
    {
        return [
            'human_explanations' => $this->humanExplanations->value,
            'interactive_behavior' => $this->humanExplanations->interactiveBehavior(),
            'unattended_behavior' => $this->humanExplanations->unattendedBehavior(),
            'authority_bearing_decisions' => 'human_required',
        ];
    }
}
