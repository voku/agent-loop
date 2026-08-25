<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * An immutable description of exactly what an install or uninstall would do,
 * computed before anything is written.
 *
 * A plan is bound to the state it was computed against via
 * {@see RepositorySetupStateToken}. Applying it re-reads that state first, so
 * a plan rendered in a browser five minutes ago cannot quietly apply to a
 * repository that has changed since.
 */
final readonly class ManagedAssetChangePlan
{
    public const string INTENT_INSTALL = 'install';
    public const string INTENT_UNINSTALL = 'uninstall';

    /**
     * @param list<ManagedAssetOperation> $operations entries that would change
     * @param list<ManagedAssetOperation> $blocked    entries deliberately left alone, with reasons
     */
    public function __construct(
        public string $intent,
        public string $agent,
        public bool $withHooks,
        public RepositorySetupStateToken $expectedState,
        public array $operations,
        public array $blocked,
    ) {
    }

    /** Stable identity of this plan's content, for a UI to echo back on submit. */
    public function planId(): string
    {
        $parts = [$this->intent, $this->agent, $this->withHooks ? 'with-hooks' : 'no-hooks'];
        foreach ([...$this->operations, ...$this->blocked] as $operation) {
            $parts[] = implode('|', [
                $operation->operation->value,
                $operation->host,
                $operation->kind->value,
                $operation->entry,
            ]);
        }

        return 'setup-plan:sha256:' . hash('sha256', implode("\n", $parts));
    }

    public function mutates(): bool
    {
        return $this->operations !== [];
    }

    /** @return list<ManagedAssetOperation> */
    public function operationsOfKind(ManagedAssetOperationKind $kind): array
    {
        return array_values(array_filter(
            [...$this->operations, ...$this->blocked],
            static fn (ManagedAssetOperation $operation): bool => $operation->operation === $kind,
        ));
    }

    /**
     * @return array{
     *     schema_version: 1,
     *     intent: string,
     *     agent: string,
     *     with_hooks: bool,
     *     plan_id: string,
     *     expected_state: string,
     *     operations: list<array<string, mixed>>,
     *     blocked: list<array<string, mixed>>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'intent' => $this->intent,
            'agent' => $this->agent,
            'with_hooks' => $this->withHooks,
            'plan_id' => $this->planId(),
            'expected_state' => $this->expectedState->value,
            'operations' => array_map(
                static fn (ManagedAssetOperation $operation): array => $operation->toArray(),
                $this->operations,
            ),
            'blocked' => array_map(
                static fn (ManagedAssetOperation $operation): array => $operation->toArray(),
                $this->blocked,
            ),
        ];
    }
}
