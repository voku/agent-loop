<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * What actually happened when a plan was applied.
 *
 * `applied` is the subset of the plan that was carried out; `blocked` carries
 * forward everything the owner refused to touch, so a caller never has to
 * diff the plan against the new state to find out what was skipped.
 */
final readonly class ManagedAssetMutationResult
{
    /**
     * @param list<ManagedAssetOperation> $applied
     * @param list<ManagedAssetOperation> $blocked
     * @param list<string>                $messages owner output worth showing a human
     */
    public function __construct(
        public ManagedAssetChangePlan $plan,
        public bool $succeeded,
        public array $applied,
        public array $blocked,
        public array $messages,
        public RepositorySetupStateToken $resultingState,
    ) {
    }

    /**
     * @return array{
     *     schema_version: 1,
     *     succeeded: bool,
     *     plan_id: string,
     *     applied: list<array<string, mixed>>,
     *     blocked: list<array<string, mixed>>,
     *     messages: list<string>,
     *     resulting_state: string
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'succeeded' => $this->succeeded,
            'plan_id' => $this->plan->planId(),
            'applied' => array_map(
                static fn (ManagedAssetOperation $operation): array => $operation->toArray(),
                $this->applied,
            ),
            'blocked' => array_map(
                static fn (ManagedAssetOperation $operation): array => $operation->toArray(),
                $this->blocked,
            ),
            'messages' => $this->messages,
            'resulting_state' => $this->resultingState->value,
        ];
    }
}
