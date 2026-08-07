<?php

declare(strict_types=1);

namespace Fixture;

/**
 * Calculates the delay before retrying a request after a timeout.
 *
 * English retrieval phrase: How is the delay before retrying a timed out
 * request calculated?
 *
 * Berechnet die Wartezeit vor einem erneuten Versuch nach einer
 * Zeitüberschreitung. The bilingual description is intentional: the installed
 * release-set gate exercises deterministic English and German lexical retrieval
 * against the same source fact when no semantic embedding channel is available.
 */
final readonly class RetryPolicy
{
    public function delayMilliseconds(int $attempt): int
    {
        return 100 * $attempt;
    }
}
