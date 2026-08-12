<?php

declare(strict_types=1);

namespace voku\AgentLoop\Cli;

/**
 * Reads `--name=value`, `--name value` and `--flag` out of a raw argv slice.
 *
 * Twelve commands carried a private copy of this loop, ten of them byte for
 * byte. Each copy is a place where the CLI could start disagreeing with itself
 * about what `--config` followed by nothing means, and a reviewer has no way to
 * tell an intentional difference from a stale one.
 *
 * Parsing only. Which options exist, which are required, and what a value means
 * stays with the command that declares them.
 */
final readonly class OptionTokens
{
    /**
     * The first value given for an option, or null when it is absent or empty.
     *
     * @param list<string> $tokens
     */
    public static function value(array $tokens, string $name): ?string
    {
        return self::values($tokens, $name)[0] ?? null;
    }

    /**
     * Every value given for a repeatable option, in the order they appeared.
     *
     * An empty value (`--name=`) is dropped rather than collected: it carries no
     * more information than omitting the option, and a caller that treated it as
     * a real value would resolve a path against the empty string.
     *
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    public static function values(array $tokens, string $name): array
    {
        $prefix = '--' . $name . '=';
        $values = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (str_starts_with($token, $prefix)) {
                $value = substr($token, strlen($prefix));
                if ($value !== '') {
                    $values[] = $value;
                }

                continue;
            }

            if ($token !== '--' . $name) {
                continue;
            }

            $candidate = $tokens[$i + 1] ?? null;
            if (is_string($candidate) && !str_starts_with($candidate, '--')) {
                $values[] = $candidate;
                ++$i;
            }
        }

        return $values;
    }

    /**
     * @param list<string> $tokens
     */
    public static function hasFlag(array $tokens, string $name): bool
    {
        return in_array('--' . $name, $tokens, true);
    }
}
