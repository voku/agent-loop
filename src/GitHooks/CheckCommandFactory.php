<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

use InvalidArgumentException;

/**
 * Turns a declared check type into the command line for the standard PHP tool.
 *
 * Every PHP repository wires the same handful of tools into its pre-commit hook and
 * ends up with a slightly different wrapper script per project. The wiring is the
 * generic part and lives here; what stays project-specific is the rule set - which
 * coding standard, which PHPStan configuration and level - so those are arguments.
 *
 * `type: command` remains the escape hatch for a tool this factory does not know.
 */
final readonly class CheckCommandFactory
{
    /** @var list<string> */
    public const array BUILT_IN_TYPES = ['command', 'php-lint', 'phpcs', 'phpcbf', 'php-cs-fixer', 'phpstan'];

    /**
     * @param array<string, mixed> $check
     * @return array{command: string, per_file: bool}
     */
    public static function create(array $check): array
    {
        $type = is_string($check['type'] ?? null) ? $check['type'] : 'command';
        $binDirectory = is_string($check['bin_dir'] ?? null) ? rtrim($check['bin_dir'], '/') : 'vendor/bin';
        $extraArguments = is_string($check['arguments'] ?? null) ? ' ' . trim($check['arguments']) : '';

        return match ($type) {
            'command' => [
                'command' => self::requiredString($check, 'command'),
                'per_file' => (bool) ($check['per_file'] ?? false),
            ],
            // `php -l` takes exactly one file, so this check is the reason per-file mode exists.
            'php-lint' => [
                'command' => 'php -l' . $extraArguments,
                'per_file' => true,
            ],
            'phpcs' => [
                'command' => $binDirectory . '/phpcs' . self::standardOption($check) . $extraArguments,
                'per_file' => false,
            ],
            'phpcbf' => [
                'command' => $binDirectory . '/phpcbf' . self::standardOption($check) . $extraArguments,
                'per_file' => false,
            ],
            'php-cs-fixer' => [
                'command' => $binDirectory . '/php-cs-fixer fix'
                    . self::optionalOption($check, 'config', '--config=')
                    . ((bool) ($check['fix'] ?? false) ? '' : ' --dry-run --diff')
                    . $extraArguments,
                'per_file' => false,
            ],
            'phpstan' => [
                'command' => $binDirectory . '/phpstan analyse --no-progress'
                    . self::optionalOption($check, 'config', '--configuration=')
                    . self::levelOption($check)
                    . self::optionalOption($check, 'memory_limit', '--memory-limit=')
                    . $extraArguments,
                'per_file' => false,
            ],
            default => throw new InvalidArgumentException(
                'Unknown pre-commit check type: ' . $type . ' (known: ' . implode(', ', self::BUILT_IN_TYPES) . ')',
            ),
        };
    }

    /**
     * `max` is a real PHPStan level and the one a project reaches for when it wants
     * the strictest analysis. `(int) 'max'` is 0, so casting turned that request into
     * `--level=0` - the *weakest* level - and the pre-commit gate kept passing while
     * checking almost nothing. A level this factory cannot read is a configuration
     * error, not a reason to guess.
     *
     * @param array<string, mixed> $check
     */
    private static function levelOption(array $check): string
    {
        $level = $check['level'] ?? null;
        if ($level === null) {
            return '';
        }
        if (is_int($level) && $level >= 0) {
            return ' --level=' . $level;
        }
        if (is_string($level) && (strtolower($level) === 'max' || preg_match('/\A\d+\z/', $level) === 1)) {
            return ' --level=' . strtolower($level);
        }

        throw new InvalidArgumentException(
            'Invalid phpstan check level: ' . (is_scalar($level) ? (string) $level : get_debug_type($level))
            . ' (expected a non-negative integer or "max")',
        );
    }

    /**
     * @param array<string, mixed> $check
     */
    private static function standardOption(array $check): string
    {
        return self::optionalOption($check, 'standard', '--standard=');
    }

    /**
     * @param array<string, mixed> $check
     */
    private static function optionalOption(array $check, string $key, string $prefix): string
    {
        $value = $check[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            return '';
        }

        return ' ' . $prefix . escapeshellarg($value);
    }

    /**
     * @param array<string, mixed> $check
     */
    private static function requiredString(array $check, string $key): string
    {
        $value = $check[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new InvalidArgumentException('A pre-commit check of type "command" needs a non-empty command.');
        }

        return $value;
    }
}
