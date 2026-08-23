<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use RuntimeException;

/** Narrow untrusted persisted execution-artifact values before typed construction. */
final readonly class ExecutionArtifactValue
{
    /** @return non-empty-string */
    public static function string(mixed $value, string $path): string
    {
        if (!is_string($value)) {
            throw new RuntimeException($path . ' requires a non-empty string.');
        }
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException($path . ' requires a non-empty string.');
        }

        return $value;
    }

    /** @return non-empty-string */
    public static function sha256(mixed $value, string $path): string
    {
        $value = self::string($value, $path);
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException($path . ' requires a sha256 digest.');
        }

        return $value;
    }

    /** @return list<non-empty-string> */
    public static function stringList(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new RuntimeException($path . ' must be an array.');
        }
        $items = [];
        foreach ($value as $index => $item) {
            $items[] = self::string($item, $path . '[' . $index . ']');
        }

        return $items;
    }
}
