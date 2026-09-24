<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLearning\DreamOutcome;
use voku\AgentLearning\EvolutionDecision;

/**
 * A Dream run as seen from the workflow: which Learning root Loop resolved for
 * this repository, and the agent-learning outcome unchanged.
 *
 * Loop adds no Dream semantics. Decisions, warnings, metrics and suppression
 * are agent-learning's; a written candidate is still only a candidate until
 * the existing human review path accepts it.
 */
final readonly class WorkflowDreamReport
{
    public function __construct(
        public string $learningRoot,
        public DreamOutcome $outcome,
    ) {
    }

    /** @return list<EvolutionDecision> */
    public function reviewableDecisions(): array
    {
        return $this->outcome->result->decisions;
    }

    public function wroteCandidates(): bool
    {
        return $this->outcome->writtenCandidateIds !== [];
    }
}
