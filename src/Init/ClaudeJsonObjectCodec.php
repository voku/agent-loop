<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use JsonException;
use stdClass;

/**
 * One narrow JSON decode boundary for Claude project configuration.
 *
 * Empty JSON objects stay stdClass so an unrelated "{}" is never rewritten as
 * "[]". Non-empty objects are converted to associative arrays for the owning
 * projectors to inspect and mutate.
 */
final readonly class ClaudeJsonObjectCodec
{
    /** @return array<string, mixed> */
    public static function decode(string $content, string $path): array
    {
        try {
            $decoded = json_decode($content, false, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException(
                'Claude project settings are not valid JSON: ' . $path,
                previous: $exception,
            );
        }
        if (!$decoded instanceof stdClass) {
            throw new InvalidArgumentException('Claude project settings must contain a JSON object: ' . $path);
        }

        return self::objectValues($decoded);
    }

    /** @return array<string, mixed> */
    private static function objectValues(stdClass $object): array
    {
        $values = [];
        foreach (get_object_vars($object) as $key => $value) {
            $values[$key] = self::decodedValue($value);
        }

        return $values;
    }

    private static function decodedValue(mixed $value): bool|float|int|string|null|stdClass|array
    {
        if ($value instanceof stdClass) {
            if (get_object_vars($value) === []) {
                return $value;
            }

            return self::objectValues($value);
        }
        if (is_array($value)) {
            return array_map(self::decodedValue(...), $value);
        }
        if ($value === null || is_bool($value) || is_float($value) || is_int($value) || is_string($value)) {
            return $value;
        }

        throw new InvalidArgumentException('Claude project settings contain an unsupported JSON value.');
    }
}
