<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

use InvalidArgumentException;

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
 *   The value is used verbatim, trailing space included: `"; "` makes `; comment` a
 *   comment and leaves `;not-a-comment` as content. Which of the two names wins
 *   depends on the running Git, so it is selected rather than preferred; see
 *   {@see self::selectCommentString()}.
 *
 * Both were verified against Git rather than inferred: see the tests, which drive a
 * real repository through each mode and read back what Git committed.
 *
 * Where Git's answer cannot be read, none is invented - the hook refuses instead.
 * That applies to `auto`, and only there.
 */
final readonly class GitCommitCleanup
{
    /**
     * Git truncates from this line under `scissors`. The marker is the comment
     * string followed by the fixed scissors text.
     */
    private const string SCISSORS = ' ------------------------ >8 ------------------------';

    private function __construct(
        public string $mode,
        public string $commentString,
    ) {
        // `auto` is resolved by Git when it prepares the buffer, and Git then writes its
        // own help and status lines using whatever it chose. By the time the hook reads
        // the file, every candidate Git picked is present in it - so scanning the buffer
        // to recover the decision concludes the opposite of the truth: an ordinary
        // `auto` resolves to `#`, the buffer is full of `#` lines, and a replay decides
        // `#` was therefore unavailable. The decision is not recoverable, so it is not
        // guessed. Modes that never consult the comment string are unaffected.
        if ($commentString === 'auto' && $this->needsCommentString()) {
            throw new InvalidArgumentException(
                'Cannot determine what Git will treat as commentary: core.commentChar/core.commentString'
                . ' is "auto" and commit.cleanup is "' . $mode . '", which removes commentary.'
                . ' Git resolves "auto" before this hook runs and the choice cannot be read back.'
                . ' Set core.commentChar to the literal character this repository uses.',
            );
        }
    }

    /**
     * Reads the settings from the repository the hook is running in.
     *
     * `git config --list -z` is asked rather than a file parsed, so system, global,
     * local and worktree scopes resolve the way Git resolves them, in the order Git
     * resolves them, with values delimited by NUL so nothing in them can be mistaken
     * for structure.
     */
    public static function fromRepository(string $rootPath): self
    {
        $entries = self::configEntries($rootPath);

        // The mode is a keyword, so surrounding whitespace carries nothing.
        $mode = trim(self::lastValue($entries, 'commit.cleanup') ?? 'default');

        return new self(
            strtolower($mode === '' ? 'default' : $mode),
            self::selectCommentString($entries, self::gitVersion($rootPath)),
        );
    }

    /**
     * Picks the comment string the *running* Git will use.
     *
     * `core.commentString` arrived in Git 2.45 as the new name for `core.commentChar`.
     * Older Git parses the key, reports it from `git config`, and then ignores it - so
     * reading it there predicts a cleanup that will not happen, in the direction that
     * blocks a commit Git would have accepted. From 2.45 the two are aliases of one
     * setting, which means neither name outranks the other: whichever appears last in
     * Git's effective order is the one in force. A fixed preference for either name is
     * wrong on one side of that boundary or the other.
     *
     * @param list<array{key: string, value: string}> $entries in Git's effective order
     */
    public static function selectCommentString(array $entries, string $gitVersion): string
    {
        $aliased = self::supportsCommentString($gitVersion);
        $selected = '#';

        foreach ($entries as $entry) {
            $key = strtolower($entry['key']);
            if ($key !== 'core.commentchar' && ($key !== 'core.commentstring' || !$aliased)) {
                continue;
            }
            // Git rejects an empty comment character, so an empty value is not a
            // selection and must not blank out the one already in force.
            if ($entry['value'] !== '') {
                $selected = $entry['value'];
            }
        }

        return $selected;
    }

    /** Git 2.45 is where `core.commentString` became a name Git actually reads. */
    public static function supportsCommentString(string $gitVersion): bool
    {
        if (preg_match('/(\d+)\.(\d+)/', $gitVersion, $matches) !== 1) {
            // An unreadable version is not evidence that the newer name works.
            return false;
        }

        return ((int) $matches[1] * 1000 + (int) $matches[2]) >= 2045;
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

        if ($this->mode === 'scissors') {
            $lines = self::untilScissors($lines, $this->commentString);
        } elseif ($this->stripsComments()) {
            $commentString = $this->commentString;
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

    /** Whether this mode has to know what commentary is in order to answer at all. */
    private function needsCommentString(): bool
    {
        return $this->stripsComments() || $this->mode === 'scissors';
    }

    /**
     * Every config entry, in Git's effective order.
     *
     * `--list -z` terminates each record with a NUL and separates the key from the
     * value with a newline, so a value carrying spaces, newlines or a trailing space
     * survives byte for byte. Reading whole records rather than one key at a time is
     * what makes the *order* of two aliased names observable at all.
     *
     * @return list<array{key: string, value: string}>
     */
    private static function configEntries(string $rootPath): array
    {
        $stdout = self::git($rootPath, ['config', '--list', '-z']);
        if ($stdout === null) {
            return [];
        }

        $entries = [];
        foreach (explode("\0", $stdout) as $record) {
            if ($record === '') {
                continue;
            }

            // A key declared without a value has no newline and no value at all.
            $separator = strpos($record, "\n");
            $entries[] = $separator === false
                ? ['key' => $record, 'value' => '']
                : ['key' => substr($record, 0, $separator), 'value' => substr($record, $separator + 1)];
        }

        return $entries;
    }

    /**
     * @param list<array{key: string, value: string}> $entries
     */
    private static function lastValue(array $entries, string $key): ?string
    {
        $value = null;
        foreach ($entries as $entry) {
            if (strtolower($entry['key']) === strtolower($key)) {
                $value = $entry['value'];
            }
        }

        return $value;
    }

    private static function gitVersion(string $rootPath): string
    {
        return self::git($rootPath, ['--version']) ?? '';
    }

    /**
     * @param non-empty-list<string> $arguments
     */
    private static function git(string $rootPath, array $arguments): ?string
    {
        if (!is_dir($rootPath)) {
            return null;
        }

        $process = @proc_open(
            array_merge(['git', '-C', $rootPath], $arguments),
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

        if (proc_close($process) !== 0 || !is_string($stdout)) {
            return null;
        }

        // Nothing beyond Git's own line terminator is removed. A comment string may
        // legitimately end in a space - `core.commentString = "; "` makes `; comment` a
        // comment while `;not-a-comment` is content - and trimming that space would drop
        // a line Git stores, which is the blind spot this whole class exists to close.
        return rtrim($stdout, "\r\n");
    }
}
