<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * Answers "is this path inside a Git working tree" by asking Git.
 *
 * The filesystem shape of `.git` is not a contract. In a linked worktree
 * (`git worktree add`) it is a *file* pointing at the shared repository, and in
 * a submodule it is a file too. Code that tested `is_dir($root . '/.git')`
 * therefore reported a perfectly valid checkout as "not a repository": `init
 * doctor` warned that Git was missing, and `init sync-githooks` silently
 * skipped `core.hooksPath`/`commit.template`, so it wrote hook files that Git
 * was never pointed at.
 *
 * Agents routinely work in linked worktrees, which is exactly where the shape
 * assumption fails, so the question is asked once, here, of the only component
 * that actually knows the answer.
 */
final readonly class GitWorkTree
{
    public static function detected(string $rootPath): bool
    {
        return self::ask($rootPath, ['git', 'rev-parse', '--is-inside-work-tree']) === 'true';
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
