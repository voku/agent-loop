<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * Answers Git working-tree questions by asking Git rather than assuming a `.git` shape.
 */
final readonly class GitWorkTree
{
    public static function detected(string $rootPath): bool
    {
        return self::ask($rootPath, ['git', 'rev-parse', '--is-inside-work-tree']) === 'true';
    }

    /** Exact current commit for provenance-bound work, or null when Git cannot provide one. */
    public static function headCommit(string $rootPath): ?string
    {
        $head = self::ask($rootPath, ['git', 'rev-parse', '--verify', 'HEAD']);
        if ($head === null || preg_match('/^[0-9a-f]{40,64}$/', $head) !== 1) {
            return null;
        }

        return $head;
    }

    /**
     * Resolve a revision expression to one exact commit, or refuse it.
     *
     * Only commit objects are accepted. Passing arguments as an array and
     * ending option parsing prevents a caller-supplied ref from becoming an
     * option to Git.
     */
    public static function resolveCommit(string $rootPath, string $revision): ?string
    {
        $revision = trim($revision);
        if ($revision === '') {
            return null;
        }

        $commit = self::ask($rootPath, ['git', 'rev-parse', '--verify', '--end-of-options', $revision . '^{commit}']);

        return $commit !== null && preg_match('/^[0-9a-f]{40,64}$/D', $commit) === 1
            ? $commit
            : null;
    }

    /**
     * Whether Git ignores a repository-relative path.
     *
     * The answer depends on global excludes, nested `.gitignore` files and
     * `info/exclude`, so Git remains the only useful authority.
     */
    public static function ignores(string $rootPath, string $relativePath): bool
    {
        return self::ask($rootPath, ['git', 'check-ignore', '--quiet', '--', $relativePath]) !== null;
    }

    /**
     * Whether a commit contains a repository-relative path, file or directory.
     *
     * A deleted path can no longer be asked about in the working tree; only the
     * commit it was planned from can prove it was there.
     */
    public static function tracksPathAt(string $rootPath, string $commit, string $relativePath): bool
    {
        if (preg_match('/^[0-9a-f]{7,64}$/', $commit) !== 1 || $relativePath === '' || str_starts_with($relativePath, '/')) {
            return false;
        }

        return self::ask($rootPath, ['git', 'cat-file', '-e', $commit . ':' . $relativePath]) !== null;
    }

    /** Repository-local/effective Git config value, or null when unset/unavailable. */
    public static function configValue(string $rootPath, string $key): ?string
    {
        return self::ask($rootPath, ['git', 'config', '--get', $key]);
    }

    /**
     * A path-typed config value: Git expands a leading `~` the way it does when
     * it uses the value itself.
     */
    public static function configPathValue(string $rootPath, string $key): ?string
    {
        return self::ask($rootPath, ['git', 'config', '--type=path', '--get', $key]);
    }

    /** The path Git uses for $name inside the repository's Git directory, e.g. "hooks". */
    public static function gitPath(string $rootPath, string $name): ?string
    {
        return self::ask($rootPath, ['git', 'rev-parse', '--git-path', $name]);
    }

    /** @param non-empty-list<string> $command */
    private static function ask(string $workingDirectory, array $command): ?string
    {
        if (!is_dir($workingDirectory)) {
            return null;
        }

        // Silenced deliberately, and only here: a machine without git makes
        // proc_open emit "posix_spawn() failed" before returning false. The
        // false is the answer callers already handle.
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
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || !is_string($stdout)) {
            return null;
        }

        return trim($stdout);
    }
}
