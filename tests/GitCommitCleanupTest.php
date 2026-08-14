<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitHooks\CommitMessageValidator;
use voku\AgentLoop\GitHooks\GitCommitCleanup;
use voku\AgentLoop\GitHooks\GitHookConfig;

/**
 * "What will Git store" is a question with two configurable answers, so it is asked
 * of Git here rather than assumed.
 *
 * Each mode test commits for real and reads the stored message back, then asserts
 * that {@see GitCommitCleanup} predicted exactly that. A hardcoded `#`-is-a-comment
 * rule got both directions wrong: under `commit.cleanup=whitespace` Git stores the
 * commentary a stripping validator would have skipped, and under `core.commentChar=;`
 * a `#` line is content Git keeps while the `;` line is what it drops.
 *
 * @internal
 */
final class GitCommitCleanupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-cleanup-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->git('init --quiet');
        $this->git('config user.email agent-loop@example.test');
        $this->git('config user.name agent-loop');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * @param array<string, string> $config
     */
    #[DataProvider('cleanupModes')]
    public function testThePredictionMatchesWhatGitStores(array $config, string $typed): void
    {
        foreach ($config as $key => $value) {
            $this->git('config ' . escapeshellarg($key) . ' ' . escapeshellarg($value));
        }

        $seen = $this->commitThroughEditor($typed);
        $stored = $this->git('log -1 --format=%B');

        $predicted = implode("\n", GitCommitCleanup::fromRepository($this->root)->committedLines($seen));

        // `%B` appends a newline of its own, so the trailing one carries no information
        // on either side. What a trailing blank line means per mode is asserted directly
        // in testVerbatimKeepsEverything.
        self::assertSame(rtrim($stored, "\n"), rtrim($predicted, "\n"));
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function cleanupModes(): array
    {
        $message = "subject line\n\n# a hash-led line\n; a semicolon-led line\nreal body\n";

        return [
            'default' => [[], $message],
            'strip' => [['commit.cleanup' => 'strip'], $message],
            'whitespace' => [['commit.cleanup' => 'whitespace'], $message],
            'verbatim' => [['commit.cleanup' => 'verbatim'], $message],
            'semicolon comment char' => [['core.commentChar' => ';'], $message],
            'semicolon comment char, explicit strip' => [
                ['core.commentChar' => ';', 'commit.cleanup' => 'strip'],
                $message,
            ],
            'scissors' => [
                ['commit.cleanup' => 'scissors'],
                "subject line\n\n# kept under scissors\n"
                . "# ------------------------ >8 ------------------------\ndropped\n",
            ],
        ];
    }

    /**
     * Under `whitespace` the commentary is part of the commit, so a rule that removed
     * it first would let forbidden stored text through.
     */
    public function testCommentaryStoredUnderWhitespaceIsStillExamined(): void
    {
        $violations = (new CommitMessageValidator(
            $this->forbidding('/\[FILL\]/'),
            GitCommitCleanup::forMode('whitespace'),
        ))->validate("subject line\n\n# WHY: [FILL]\n");

        self::assertNotSame([], $violations);
    }

    /** Under `strip` the same line is discarded by Git and must not block the commit. */
    public function testTheSameCommentaryUnderStripDoesNotBlockTheCommit(): void
    {
        $violations = (new CommitMessageValidator(
            $this->forbidding('/\[FILL\]/'),
            GitCommitCleanup::forMode('strip'),
        ))->validate("subject line\n\n# WHY: [FILL]\nreal body\n");

        self::assertSame([], $violations);
    }

    /**
     * With a configured comment character, `#` is ordinary content Git keeps and the
     * configured character is what it drops. Both halves have to move together.
     */
    public function testTheConfiguredCommentCharacterDecidesWhatIsCommentary(): void
    {
        $validator = new CommitMessageValidator(
            $this->forbidding('/\[FILL\]/'),
            GitCommitCleanup::forMode('strip', ';'),
        );

        self::assertNotSame(
            [],
            $validator->validate("subject line\n\n# WHY: [FILL]\nreal body\n"),
            'a hash line is stored when the comment character is a semicolon',
        );
        self::assertSame(
            [],
            $validator->validate("subject line\n\n; WHY: [FILL]\nreal body\n"),
            'the semicolon line is the one Git discards',
        );
    }

    /**
     * Git picks the comment string when it *prepares* the buffer and then writes its
     * own help lines with the character it chose, so the file the hook receives always
     * contains that character at the start of a line. Scanning it to recover the
     * decision therefore concludes the opposite of the truth. This drives a real
     * `auto` commit to show the contaminated buffer, and asserts the hook refuses
     * rather than inventing an answer from it.
     */
    public function testAutoIsRefusedRatherThanInferredFromTheBuffer(): void
    {
        $this->git('config core.commentChar auto');
        $seen = $this->commitThroughEditor("subject line\n\nbody line\n");

        // Git resolved `auto` to `#` and commented its own help with it.
        self::assertStringContainsString("\n# Please enter the commit message", $seen);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/auto.*cannot be read back/s');

        GitCommitCleanup::fromRepository($this->root);
    }

    /**
     * A mode that never consults the comment string has nothing to refuse.
     *
     * @param list<string> $expected
     */
    #[DataProvider('modesThatIgnoreCommentary')]
    public function testAutoIsHarmlessForModesThatKeepCommentary(string $mode, array $expected): void
    {
        $cleanup = GitCommitCleanup::forMode($mode, 'auto');

        self::assertSame($expected, $cleanup->committedLines("subject\n# kept\n"));
    }

    /** @return list<array{string, list<string>}> */
    public static function modesThatIgnoreCommentary(): array
    {
        return [
            ['whitespace', ['subject', '# kept']],
            // verbatim keeps the trailing blank line the final newline produces.
            ['verbatim', ['subject', '# kept', '']],
        ];
    }

    /**
     * A comment string may end in a space. `"; "` makes `; comment` commentary and
     * leaves `;not-a-comment` as content Git stores, so trimming the configured value
     * would drop a stored line - reopening the blind spot this class closes.
     */
    public function testATrailingSpaceInTheCommentStringIsSignificant(): void
    {
        $cleanup = GitCommitCleanup::forMode('strip', '; ');

        self::assertSame(
            ['subject', ';not-a-comment', 'body'],
            $cleanup->committedLines("subject\n; a real comment\n;not-a-comment\nbody\n"),
        );
    }

    /**
     * The selector, exercised against every Git generation without needing more than
     * one Git binary. The two names are one setting from 2.45 onward, so order decides;
     * before 2.45 the newer name is reported by `git config` and then ignored by Git
     * itself, so reading it would predict a cleanup that never happens.
     *
     * @param list<array{key: string, value: string}> $entries
     */
    #[DataProvider('commentStringSelections')]
    public function testTheSelectorFollowsTheRunningGitsSemantics(
        string $gitVersion,
        array $entries,
        string $expected,
    ): void {
        self::assertSame($expected, GitCommitCleanup::selectCommentString($entries, $gitVersion));
    }

    /** @return array<string, array{string, list<array{key: string, value: string}>, string}> */
    public static function commentStringSelections(): array
    {
        $commentChar = ['key' => 'core.commentchar', 'value' => ';'];
        $commentString = ['key' => 'core.commentstring', 'value' => '//'];

        return [
            '2.44 ignores commentString even when it is last' => [
                'git version 2.44.0',
                [$commentChar, $commentString],
                ';',
            ],
            '2.44 with only commentString falls back to the default' => [
                'git version 2.44.0',
                [$commentString],
                '#',
            ],
            '2.45 aliases them, so a later commentChar wins' => [
                'git version 2.45.0',
                [$commentString, $commentChar],
                ';',
            ],
            '2.45 aliases them, so a later commentString wins' => [
                'git version 2.45.0',
                [$commentChar, $commentString],
                '//',
            ],
            '2.45 keeps a trailing space byte for byte' => [
                'git version 2.45.1',
                [['key' => 'core.commentString', 'value' => '; ']],
                '; ',
            ],
            'a later empty value does not blank out the one in force' => [
                'git version 2.45.0',
                [$commentChar, ['key' => 'core.commentchar', 'value' => '']],
                ';',
            ],
            'an unreadable version is not evidence the newer name works' => [
                'git version (unknown)',
                [$commentString],
                '#',
            ],
            'nothing configured' => ['git version 2.45.0', [], '#'],
        ];
    }

    /** The version boundary itself, stated once. */
    #[DataProvider('versionSupport')]
    public function testTheCommentStringNameIsReadOnlyFrom245(string $gitVersion, bool $supported): void
    {
        self::assertSame($supported, GitCommitCleanup::supportsCommentString($gitVersion));
    }

    /** @return list<array{string, bool}> */
    public static function versionSupport(): array
    {
        return [
            ['git version 2.43.0', false],
            ['git version 2.44.4', false],
            ['git version 2.45.0', true],
            ['git version 2.50.1', true],
            ['git version 3.0.0', true],
            ['nonsense', false],
        ];
    }

    /**
     * Git returns a trailing space untouched, which is what the reader relies on. True
     * of every Git, so no gate: this pins the transport, not the semantics.
     *
     * Read through a pipe rather than `exec()`, which strips trailing whitespace from
     * every line it hands back and would destroy the very byte under test.
     */
    public function testGitReportsATrailingSpaceVerbatim(): void
    {
        $this->git("config core.commentString '; '");

        self::assertSame("core.commentstring\n; ", $this->gitRecordFor('core.commentstring'));
    }

    /** The `--list -z` record for one key, byte for byte. */
    private function gitRecordFor(string $key): string
    {
        $pipes = [];
        $process = proc_open(
            ['git', '-C', $this->root, 'config', '--list', '-z'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);

        $stdout = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        foreach (explode("\0", $stdout) as $record) {
            if (str_starts_with($record, $key . "\n")) {
                return $record;
            }
        }

        self::fail('No config record for ' . $key . ' in: ' . str_replace("\0", '|', $stdout));
    }

    /** And the reader agrees with whichever Git is actually running. */
    public function testTheReaderMatchesTheRunningGitsSupport(): void
    {
        $this->git("config core.commentString '; '");

        $expected = GitCommitCleanup::supportsCommentString($this->git('--version')) ? '; ' : '#';

        self::assertSame($expected, GitCommitCleanup::fromRepository($this->root)->commentString);
    }

    /** A lone `core.commentChar` is read the same way on every Git. */
    public function testCommentCharIsReadOnEveryGitVersion(): void
    {
        $this->git('config core.commentChar ";"');

        self::assertSame(';', GitCommitCleanup::fromRepository($this->root)->commentString);
    }

    /**
     * And Git agrees: `; comment` is dropped, `;not-a-comment` is stored.
     */
    public function testGitAgreesThatOnlyTheSpacedFormIsCommentary(): void
    {
        $this->requireCommentStringSupport();
        $this->git("config core.commentString '; '");
        $this->git('config commit.cleanup strip');

        $seen = $this->commitThroughEditor("subject line\n\n; a real comment\n;not-a-comment\nbody\n");
        $stored = rtrim($this->git('log -1 --format=%B'), "\n");

        self::assertStringNotContainsString('; a real comment', $stored);
        self::assertStringContainsString(';not-a-comment', $stored);
        self::assertSame(
            $stored,
            implode("\n", GitCommitCleanup::fromRepository($this->root)->committedLines($seen)),
        );
    }

    /** `verbatim` keeps everything, including the blank lines other modes trim. */
    public function testVerbatimKeepsEverything(): void
    {
        $lines = GitCommitCleanup::forMode('verbatim')->committedLines("\nsubject\n\n# comment\n");

        self::assertSame(['', 'subject', '', '# comment', ''], $lines);
    }

    /**
     * The one boundary this class does not close, pinned so it stays a known shape
     * rather than a surprise: `commit.cleanup=default` with `git commit -m` stores a
     * hash-led line, and the hook cannot tell that path from the editor path, so it
     * resolves `default` as `strip`. Setting `commit.cleanup` explicitly is the escape
     * hatch, and the mode test above proves the explicit setting is honoured.
     */
    public function testDefaultModeResolvesAsStripAndSaysSo(): void
    {
        $cleanup = GitCommitCleanup::forMode('default');

        self::assertTrue($cleanup->stripsComments());
        self::assertSame(['subject'], $cleanup->committedLines("subject\n# dropped as if edited\n"));

        // What Git really does on the `-m` path, for contrast.
        file_put_contents($this->root . '/tracked.txt', "content\n");
        $this->git('add tracked.txt');
        $this->git('commit --quiet -m ' . escapeshellarg("subject\n\n# typed by hand\nbody"));
        self::assertStringContainsString('# typed by hand', $this->git('log -1 --format=%B'));
    }

    /** An unreadable or absent repository must not crash the hook. */
    public function testAMissingRepositoryFallsBackToTheSafeDefault(): void
    {
        $cleanup = GitCommitCleanup::fromRepository($this->root . '/does-not-exist');

        self::assertTrue($cleanup->stripsComments());
    }

    /**
     * `core.commentString` landed in Git 2.45; older Git parses the key and then
     * ignores it, so asserting Git's behaviour against it would assert nothing. The
     * unit-level expectations above still run everywhere.
     */
    private function requireCommentStringSupport(): void
    {
        $version = $this->git('--version');
        if (preg_match('/(\d+)\.(\d+)/', $version, $matches) !== 1) {
            self::markTestSkipped('Cannot read the Git version: ' . $version);
        }
        if ((int) $matches[1] * 1000 + (int) $matches[2] < 2045) {
            self::markTestSkipped('core.commentString needs Git 2.45; found ' . trim($version));
        }
    }

    private function forbidding(string $pattern): GitHookConfig
    {
        return GitHookConfig::fromArray(['commit_msg' => [
            'forbidden_patterns' => [['pattern' => $pattern, 'message' => 'Unreplaced template marker.']],
        ]]);
    }

    /** @return string the message file exactly as the hook would receive it */
    private function commitThroughEditor(string $typed): string
    {
        $capture = $this->root . '/seen-by-hook.txt';
        if (!is_dir($this->root . '/.githooks')) {
            mkdir($this->root . '/.githooks', 0o775, true);
            file_put_contents(
                $this->root . '/.githooks/commit-msg',
                "#!/bin/sh\ncp \"\$1\" " . escapeshellarg($capture) . "\n",
            );
            chmod($this->root . '/.githooks/commit-msg', 0o775);
            $this->git('config core.hooksPath .githooks');
        }

        $editor = $this->root . '/editor.sh';
        file_put_contents($this->root . '/typed.txt', $typed);
        file_put_contents(
            $editor,
            "#!/bin/sh\ncat " . escapeshellarg($this->root . '/typed.txt') . " \"\$1\" > \"\$1.tmp\" && mv \"\$1.tmp\" \"\$1\"\n",
        );
        chmod($editor, 0o775);

        $file = $this->root . '/tracked-' . bin2hex(random_bytes(4)) . '.txt';
        file_put_contents($file, "content\n");
        $this->git('add ' . escapeshellarg(basename($file)));

        exec(
            'GIT_EDITOR=' . escapeshellarg($editor)
            . ' git -C ' . escapeshellarg($this->root) . ' commit --quiet 2>/dev/null',
            $output,
            $exitCode,
        );
        self::assertSame(0, $exitCode, 'the fixture commit must succeed: ' . implode("\n", $output));

        return (string) file_get_contents($capture);
    }

    private function git(string $arguments): string
    {
        $output = [];
        exec('git -C ' . escapeshellarg($this->root) . ' ' . $arguments . ' 2>/dev/null', $output);

        return implode("\n", $output);
    }
}
