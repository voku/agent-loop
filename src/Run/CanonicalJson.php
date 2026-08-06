<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use JsonException;
use RuntimeException;

/**
 * Deterministic JSON for persisted run projections.
 *
 * Lists keep their order. Object keys are sorted recursively, so equivalent
 * projections produce byte-identical files regardless of construction order.
 */
final class CanonicalJson
{
    /** @param array<string, mixed> $value */
    public static function pretty(array $value): string
    {
        try {
            return json_encode(
                self::normalize($value),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new RuntimeException('Unable to encode canonical run-manifest JSON: ' . $exception->getMessage(), 0, $exception);
        }
    }

    private static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $normalized = [];
            foreach ($value as $item) {
                $normalized[] = self::normalize($item);
            }

            return $normalized;
        }

        ksort($value, SORT_STRING);
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[$key] = self::normalize($item);
        }

        return $normalized;
    }
}
