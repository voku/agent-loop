<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * What the current package sources say should exist at one projection target.
 *
 * "We cannot tell" and "nothing is expected" are different facts, and a bare
 * `?array` loses the reason for the first one. Keeping the reason matters for
 * removal: an unreadable source definition must fail closed with an
 * explanation, not silently look like an empty expectation.
 */
final readonly class ManagedAssetEntryExpectation
{
    /**
     * @param list<string>|null     $entries null when the source could not be read
     * @param non-empty-string|null $failure why the entries are unknown
     */
    private function __construct(
        public ?array $entries,
        public ?string $failure,
    ) {
    }

    /** @param list<string> $entries */
    public static function known(array $entries): self
    {
        return new self($entries, null);
    }

    /** @param non-empty-string $reason */
    public static function unknown(string $reason): self
    {
        return new self(null, $reason);
    }

    public function isKnown(): bool
    {
        return $this->entries !== null;
    }
}
