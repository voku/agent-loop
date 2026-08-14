<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * Answers repository-shape questions by asking Git instead of inspecting `.git`.
 */
final readonly class GitWorkTree
{
    public static function detected(string $rootPath): bool
    {
        return self::ask($rootPath, ['git', 'rev-parse', '--is-inside-work-tree']) === 'true';
    }

    /**
     * Whether Git ignores a repository-relative path.
     */
    public static function ignores(string $rootPath, string $relativePath): bool
    {
        return self::ask($rootPath, ['git', 'check-ignore', '--quiet', '--', $relativePath]) !== null;
    }

    /** Repository-local/effective Git config value, or null when unset/unavailable. */
    public static function configValue(string $rootPath, string $key): ?string
    {
        return self::ask($rootPath, ['git', 'config', '--get', $key]);
    }

    /** @param non-empty-list<string> $command */
    private static function ask(string $workingDirectory, array $command): ?string
    {
        if (!is_dir($workingDirectory)) {
            return null;
        }

        // Silenced deliberately, and only here: a machine without git makes
        // proc_open emit "posix_spawn() failed" before returning false. The
        // false is the answer we already handle, and the warning would be
        // printed by `init doctor` - the one command whose job is to report
        // calmly on an environment that may be missing things.
        $process = @proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        // Drained as well: an undrained stderr pipe can block the child once its buffer fills.
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || !is_string($stdout)) {
            return null;
        }

        return trim($stdout);
    }
}
