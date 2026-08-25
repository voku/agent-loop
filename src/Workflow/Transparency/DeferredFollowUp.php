<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use voku\AgentLearning\RunLearningDecision;

/**
 * Work a human deliberately deferred, as recorded by the durable Run Learning decision.
 *
 * Only an explicit `follow_up_required` close-out with a follow-up reference
 * counts. Absence of work is not a defer, and a projection that guessed one
 * would invent an owner decision nobody made.
 */
final readonly class DeferredFollowUp
{
    public function __construct(
        public string $runId,
        public string $followUpRef,
        public string $decidedBy,
        public string $decidedAt,
        public string $reason,
    ) {
    }

    public static function fromDecision(RunLearningDecision $decision): ?self
    {
        if ($decision->followUpRef === null) {
            return null;
        }

        return new self(
            $decision->runId,
            $decision->followUpRef,
            $decision->decidedBy,
            $decision->decidedAt,
            $decision->reason,
        );
    }

    /**
     * @return array{
     *     run_id: string,
     *     follow_up_ref: string,
     *     decided_by: string,
     *     decided_at: string,
     *     reason: string
     * }
     */
    public function toArray(): array
    {
        return [
            'run_id' => $this->runId,
            'follow_up_ref' => $this->followUpRef,
            'decided_by' => $this->decidedBy,
            'decided_at' => $this->decidedAt,
            'reason' => $this->reason,
        ];
    }
}
