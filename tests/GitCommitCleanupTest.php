<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

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
