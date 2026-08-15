<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use HelgeSverre\Toon\Toon;

/**
 * Serializer for ephemeral structured output intended for an agent to read.
 *
 * Durable/replayable artifacts keep their owner-defined canonical encoding;
 * this class is only for read-only wire projections where JSON key repetition
 * is context-window overhead rather than evidence.
 */
final readonly class AgentOutput
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function toon(array $payload): string
    {
        return Toon::encode($payload) . "\n";
    }
}
