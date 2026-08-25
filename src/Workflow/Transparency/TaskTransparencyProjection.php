<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * Everything a host needs to explain a governed task truthfully, with each
 * answer still carrying the kind of truth it is.
 *
 * What this deliberately cannot say: that implementation is complete, that
 * acceptance criteria are satisfied, or that a review passed. Those are owner
 * decisions with their own evidence, and no amount of Git observation
 * substitutes for one.
 */
final readonly class TaskTransparencyProjection
{
    public const string SCHEMA_VERSION = '1.0';

    public function __construct(
        public string $taskId,
        public ContractBoundary $contract,
        public RepositoryObservation $observation,
        public ScopeCoverage $scopeCoverage,
        public ImplementationIdentity $implementation,
        public ContextCoverage $context,
        public ReviewDetail $review,
        public ?BlockedRecord $blocked,
        public ?DeferredFollowUp $deferredFollowUp,
    ) {
    }

    /**
     * Every categorised fact in one deterministic list.
     *
     * `UNKNOWN` is emitted when repository observation was not available: a host
     * must be able to say "not observed" rather than show an empty change set
     * that reads like a clean tree.
     *
     * @return list<TransparencyItem>
     */
    public function items(): array
    {
        $items = [];
        foreach ($this->contract->nonGoals as $nonGoal) {
            $items[] = new TransparencyItem(TransparencyCategory::CONTRACT_NON_GOAL, $nonGoal);
        }
        if ($this->observation->isObserved()) {
            foreach ($this->scopeCoverage->changedInScope as $path) {
                $items[] = new TransparencyItem(TransparencyCategory::CHANGED_IN_SCOPE, $path);
            }
            foreach ($this->scopeCoverage->changedOutsideScope as $path) {
                $items[] = new TransparencyItem(TransparencyCategory::CHANGED_OUTSIDE_SCOPE, $path);
            }
        } else {
            $items[] = new TransparencyItem(
                TransparencyCategory::UNKNOWN,
                'repository_observation',
                $this->observation->unavailableReason,
            );
        }
        foreach ($this->context->skipped as $skipped) {
            $items[] = new TransparencyItem(TransparencyCategory::CONTEXT_SKIPPED, $skipped);
        }
        foreach ($this->context->omitted as $omission) {
            $items[] = new TransparencyItem(
                TransparencyCategory::CONTEXT_OMITTED,
                $omission->category,
                $omission->count . ' omitted because the context budget was exhausted',
            );
        }
        foreach ($this->review->findings as $finding) {
            $items[] = new TransparencyItem(
                TransparencyCategory::REVIEW_FINDING,
                $finding->severity . ' ' . $finding->id,
                $finding->message,
            );
        }
        if ($this->blocked !== null) {
            $items[] = new TransparencyItem(
                TransparencyCategory::BLOCKED,
                $this->blocked->state,
                $this->blocked->reason,
            );
        }
        if ($this->deferredFollowUp !== null) {
            $items[] = new TransparencyItem(
                TransparencyCategory::FUTURE_WORK_DEFERRED,
                $this->deferredFollowUp->followUpRef,
                $this->deferredFollowUp->reason,
            );
        }

        return $items;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task_id' => $this->taskId,
            'contract' => $this->contract->toArray(),
            'observation' => $this->observation->toArray(),
            'scope_coverage' => $this->scopeCoverage->toArray(),
            'implementation' => $this->implementation->toArray(),
            'context' => $this->context->toArray(),
            'review' => $this->review->toArray(),
            'blocked' => $this->blocked?->toArray(),
            'future_work_deferred' => $this->deferredFollowUp?->toArray(),
            'items' => array_map(static fn (TransparencyItem $item): array => $item->toArray(), $this->items()),
        ];
    }
}
