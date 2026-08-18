<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use RuntimeException;

/**
 * Read-side counterpart to CanonicalJson for persisted run projections.
 *
 * Decoded workflow state is untrusted input: every store that reads it needs
 * the same field checks, and the failure has to name the file it came from.
 */
final class PersistedJson
{
    /** @param array<string, mixed> $data */
    public static function requiredString(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($path . ' requires non-empty ' . $key . '.');
        }

        return trim($value);
    }
}
