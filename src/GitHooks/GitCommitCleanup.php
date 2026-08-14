<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

/**
 * The only code that answers "what will Git store for this message".
 *
 * A `commit-msg` hook is handed the message *file*, and Git cleans it afterwards.
 * How it cleans depends on two settings the hook has to read rather than assume:
 *
 * - `commit.cleanup` decides whether commentary survives. Under `whitespace`,
 *   `verbatim` and `scissors` it is stored, so a rule that removed it would skip
 *   text that ends up in the repository. Only `strip` removes it.
 * - `core.commentString` / `core.commentChar` decides what commentary *is*. With
 *   `core.commentChar=;` a `#` line is ordinary content Git keeps, and a `;` line
 *   is the comment Git drops - the exact opposite of the hardcoded assumption.
 *
 * Both were verified against Git rather than inferred: see the tests, which drive a
 * real repository through each mode and read back what Git committed.
 */
final readonly class GitCommitCleanup
{
    /**
     * Git truncates from this line under `scissors`. The marker is the comment
     * string followed by the fixed scissors text.
     */
    private const string SCISSORS = ' ------------------------ >8 ------------------------';

    /**
     * Git's own candidate list for `core.commentChar=auto`, in its order.
     *
     * @var list<string>
     */
    private const array AUTO_CANDIDATES = ['#', ';', '@', '!', '$', '%', '^', '&', '|', ':'];

    private function __construct(
        public string $mode,
        public string $commentString,
    ) {
    }

    /**
     * Reads the two settings from the repository the hook is running in.
     *
     * `git config` is asked rather than a file parsed, so system, global, local and
     * worktree scopes resolve the way Git resolves them.
     */
    public static function fromRepository(string $rootPath): self
    {
        $mode = self::gitConfig($rootPath, 'commit.cleanup') ?? 'default';
        $commentString = self::gitConfig($rootPath, 'core.commentString')
            ?? self::gitConfig($rootPath, 'core.commentChar')
            ?? '#';

        return new self(strtolower($mode), $commentString === '' ? '#' : $commentString);
    }

    public static function forMode(string $mode, string $commentString = '#'): self
    {
        return new self(strtolower($mode), $commentString);
    }

    /**
     * The message as Git will store it, split into lines.
     *
     * `default` is resolved as `strip`. Git's real `default` is `strip` for a message
     * that went through the editor and `whitespace` for one passed with `-m`, and the
     * hook cannot observe which happened: both write the same `COMMIT_EDITMSG`. Strip
     * is the resolution because the editor path is the one that carries text the
     * committer did not write and cannot delete - Git's own commentary and the
     * `commit.template` this package installs - so reading it as stored produces a
     * commit that can never be made to pass. The residual gap is narrow and pinned by
     * a test: a `#` line typed into `git commit -m` is stored by Git and not examined
     * here. A repository that cares can close it by setting `commit.cleanup`
     * explicitly, which is honoured exactly.
     *
     * @return list<string>
     */
    public function committedLines(string $message): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $message) ?: [];

        if ($this->mode === 'verbatim') {
            return $lines;
        }

        $commentString = $this->resolvedCommentString($lines);

        if ($this->mode === 'scissors') {
            $lines = self::untilScissors($lines, $commentString);
        } elseif ($this->stripsComments()) {
            $lines = array_values(array_filter(
                $lines,
                static fn (string $line): bool => !str_starts_with($line, $commentString),
            ));
        }

        // Every mode but `verbatim` trims trailing whitespace and drops leading and
        // trailing blank lines. Git also collapses runs of blank lines; that is not
        // modelled because no rule here reads blank lines.
        $lines = array_map(static fn (string $line): string => rtrim($line), $lines);
        while ($lines !== [] && $lines[0] === '') {
            array_shift($lines);
        }
        while ($lines !== [] && $lines[count($lines) - 1] === '') {
            array_pop($lines);
        }

        return $lines;
    }

    /** True when Git drops commentary from this message before storing it. */
    public function stripsComments(): bool
    {
        return $this->mode === 'strip' || $this->mode === 'default';
    }

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function untilScissors(array $lines, string $commentString): array
    {
        $scissors = $commentString . self::SCISSORS;
        $kept = [];
        foreach ($lines as $line) {
            if (rtrim($line) === $scissors) {
                break;
            }
            $kept[] = $line;
        }

        return $kept;
    }

    /**
     * `auto` is resolved by Git when it *prepares* the buffer, before the committer
     * ever sees it, so the final file cannot be replayed to recover the choice. Git
     * keeps its first candidate unless the text it is about to comment already uses
     * it, which is what this reproduces; in practice the answer is `#`.
     *
     * @param list<string> $lines
     */
    private function resolvedCommentString(array $lines): string
    {
        if ($this->commentString !== 'auto') {
            return $this->commentString;
        }

        foreach (self::AUTO_CANDIDATES as $candidate) {
            foreach ($lines as $line) {
                if (str_starts_with($line, $candidate)) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return '#';
    }

    private static function gitConfig(string $rootPath, string $key): ?string
    {
        if (!is_dir($rootPath)) {
            return null;
        }

        $process = @proc_open(
            ['git', '-C', $rootPath, 'config', '--get', $key],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (proc_close($process) !== 0 || !is_string($stdout) || trim($stdout) === '') {
            return null;
        }

        return trim($stdout);
    }
}
