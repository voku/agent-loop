<?php

declare(strict_types=1);

namespace Fixture;

/**
 * Calculates the delay before retrying a request after a timeout.
 *
 * Berechnet die Wartezeit vor einem erneuten Versuch nach einer
 * Zeitüberschreitung. The bilingual description is intentional: the installed
 * release-set gate exercises English and German retrieval against the same
 * source fact.
 */
final readonly class RetryPolicy
{
    public function delayMilliseconds(int $attempt): int
    {
        return 100 * $attempt;
    }
}
