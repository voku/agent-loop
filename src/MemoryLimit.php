<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * Interprets PHP's `memory_limit` shorthand so a floor can be applied without ever lowering it.
 *
 * `(int) '2G'` is `2`, not 2147483648, so a naive comparison against a megabyte number decides that
 * an operator who deliberately allowed two gigabytes wants 512 megabytes instead. The commands that
 * need a floor - map builds, recall compiles over a large index - are exactly the ones that get
 * started with `-d memory_limit=4G`, so that mistake breaks the case the floor exists for.
 */
final class MemoryLimit
{
    public const MINIMUM = '512M';

    /**
     * @return int|null bytes, or null when the limit is unlimited or unparseable
     */
    public static function toBytes(string $value): ?int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return null;
        }

        if (preg_match('/^(\d+)\s*([KMG])?$/i', $value, $matches) !== 1) {
            return null;
        }

        $bytes = (int) $matches[1];

        return match (strtoupper($matches[2] ?? '')) {
            'K' => $bytes * 1024,
            'M' => $bytes * 1024 * 1024,
            'G' => $bytes * 1024 * 1024 * 1024,
            default => $bytes,
        };
    }

    /**
     * True only when the current limit is a real number below the floor. Unlimited and unparseable
     * values are left alone: raising them is impossible, and replacing them would be a downgrade.
     */
    public static function shouldRaise(string $current, string $minimum = self::MINIMUM): bool
    {
        $currentBytes = self::toBytes($current);
        $minimumBytes = self::toBytes($minimum);

        return $currentBytes !== null && $minimumBytes !== null && $currentBytes < $minimumBytes;
    }
}
