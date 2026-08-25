<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * Typed drift classification for one {@see ManagedAssetTarget}.
 *
 * The buckets are the owner's existing vocabulary from
 * {@see ManagedAssetDriftInspector}; this object only gives them a typed home
 * and records why a target could not be classified at all.
 *
 * `manifestState` distinguishes three genuinely different situations that a
 * boolean would flatten: there is no manifest (nothing was ever projected
 * here), the manifest is unreadable (fail closed), or the manifest is present
 * and its entries were classified.
 */
final readonly class ManagedAssetDriftProjection
{
    public const string MANIFEST_MISSING = 'missing';
    public const string MANIFEST_UNREADABLE = 'unreadable';
    public const string MANIFEST_PRESENT = 'present';

    /**
     * @param list<string> $current
     * @param list<string> $locallyModified
     * @param list<string> $stale
     * @param list<string> $incompatible
     * @param list<string> $projectOwned
     * @param list<string> $unverifiable
     */
    public function __construct(
        public ManagedAssetTarget $target,
        public string $manifestState,
        public array $current = [],
        public array $locallyModified = [],
        public array $stale = [],
        public array $incompatible = [],
        public array $projectOwned = [],
        public array $unverifiable = [],
        public ?string $failure = null,
    ) {
    }

    public static function missing(ManagedAssetTarget $target): self
    {
        return new self($target, self::MANIFEST_MISSING);
    }

    public static function unreadable(ManagedAssetTarget $target, string $failure): self
    {
        return new self($target, self::MANIFEST_UNREADABLE, failure: $failure);
    }

    /**
     * @param array{
     *     current:list<string>,
     *     locally_modified:list<string>,
     *     stale:list<string>,
     *     incompatible:list<string>,
     *     project_owned:list<string>,
     *     unverifiable:list<string>
     * } $states
     */
    public static function fromStates(ManagedAssetTarget $target, array $states): self
    {
        return new self(
            $target,
            self::MANIFEST_PRESENT,
            $states['current'],
            $states['locally_modified'],
            $states['stale'],
            $states['incompatible'],
            $states['project_owned'],
            $states['unverifiable'],
        );
    }

    /** Entries that must never be removed automatically, whatever the caller asks for. */
    public function blockingRemoval(): bool
    {
        return $this->locallyModified !== []
            || $this->incompatible !== []
            || $this->unverifiable !== []
            || $this->manifestState === self::MANIFEST_UNREADABLE;
    }

    public function hasDrift(): bool
    {
        return $this->locallyModified !== []
            || $this->stale !== []
            || $this->incompatible !== []
            || $this->unverifiable !== [];
    }

    /** @return array<non-empty-string, list<string>> */
    public function buckets(): array
    {
        return [
            'current' => $this->current,
            'locally_modified' => $this->locallyModified,
            'stale' => $this->stale,
            'incompatible' => $this->incompatible,
            'project_owned' => $this->projectOwned,
            'unverifiable' => $this->unverifiable,
        ];
    }
}
