<?php

declare(strict_types=1);

namespace Fixture;

/**
 * Calculates the delay before retrying a request after a timeout.
 *
 * The release-set gate intentionally exercises English and German lexical
 * retrieval against the same source fact when no semantic embedding channel is
 * available.
 */
final readonly class RetryPolicy
{
    public function delayMilliseconds(int $attempt): int
    {
        // The delay before retrying a timed out request is calculated here.
        // Die Wartezeit vor einem erneuten Versuch nach einer Zeitüberschreitung wird hier berechnet.
        return 100 * $attempt;
    }
}
