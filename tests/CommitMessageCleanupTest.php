<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitHooks\CommitMessageValidator;
use voku\AgentLoop\GitHooks\GitHookConfig;

/**
 * The commit-msg hook is handed the message *file*, not the message.
 *
 * At that point the file still carries Git's own comment block, whatever
 * `commit.template` this package installed, and the blank lines the editor left
 * behind. Git strips all of it right after the hook returns, so a rule that reads
 * the raw file is judging text the committer never wrote and cannot remove.
 *
 * The first test here checks that assumption against Git itself rather than
 * against a comment in this package, because everything below only matters if Git
 * really does hand the hook the uncleaned file.
 *
 * @internal
 */
final class CommitMessageCleanupTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-commit-cleanup-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * Pins the environmental fact the validator is built on: at commit-msg time the
     * message file still has the comments and the leading blank lines, and the commit
     * Git goes on to store has neither.
     */
    public function testGitHandsTheHookAMessageItHasNotCleanedYet(): void
    {
        $this->initRepository();

        $capture = $this->root . '/seen-by-hook.txt';
        mkdir($this->root . '/.githooks', 0o775, true);
        file_put_contents(
            $this->root . '/.githooks/commit-msg',
            "#!/bin/sh\ncp \"\$1\" " . escapeshellarg($capture) . "\n",
        );
        chmod($this->root . '/.githooks/commit-msg', 0o775);
        $this->git('config core.hooksPath .githooks');

        file_put_contents($this->root . '/.gitmessage', "\n# WHY: [FILL]\n");
        $this->git('config commit.template .gitmessage');

        file_put_contents($this->root . '/tracked.txt', "content\n");
        $this->git('add tracked.txt');
        $this->commitThroughEditor("\nsubject line\n\nWHY: a concrete recorded reason\n");

        $seen = (string) file_get_contents($capture);
        self::assertStringContainsString('# WHY: [FILL]', $seen, 'the template comment reaches the hook');
        self::assertStringStartsWith("\n", $seen, 'the leading blank line reaches the hook');

        $stored = $this->git('log -1 --format=%B');
        self::assertStringNotContainsString('#', $stored, 'Git strips the comments it added');
        self::assertStringStartsWith('subject line', $stored, 'Git strips the leading blank lines');
    }

    /**
     * The rule exists to catch a template guide line the committer forgot to replace.
     * The guide line itself lives in a comment, so matching it there blocked every
     * single commit in a repository that uses the `--commit-template` this package
     * installs - and no edit to the message could clear it.
     */
    public function testATemplatePlaceholderInACommentDoesNotBlockTheCommit(): void
    {
        $violations = (new CommitMessageValidator($this->config()))->validate(
            "[+]: \"agent-loop\" -> record the real reason\n"
            . "\n"
            . "WHY:\n"
            . "- TICKET-42 blocked the nightly sync\n"
            . "\n"
            . "# WHY: [FILL]\n"
            . "# Please enter the commit message for your changes.\n",
        );

        self::assertSame([], $violations);
    }

    /** The same marker on a line the committer actually wrote is still a violation. */
    public function testATemplatePlaceholderLeftInTheRealBodyStillBlocksTheCommit(): void
    {
        $violations = (new CommitMessageValidator($this->config()))->validate(
            "[+]: \"scope\" -> subject\n\nWHY: [FILL]\n",
        );

        self::assertNotSame([], $violations);
        self::assertStringContainsString('placeholder', $violations[0]);
    }

    /**
     * Git's comment block lists the staged paths, so a forbidden pattern matching an
     * unfinished-work marker used to fire on a repository that merely staged a file
     * whose name contains one.
     */
    public function testGitsOwnStatusCommentaryCannotTripAForbiddenPattern(): void
    {
        $config = GitHookConfig::fromArray(['commit_msg' => [
            'forbidden_patterns' => [['pattern' => '/TODO/', 'message' => 'Unfinished marker in commit message.']],
        ]]);

        $violations = (new CommitMessageValidator($config))->validate(
            "add the checklist\n"
            . "\n"
            . "# Changes to be committed:\n"
            . "#\tnew file:   TODO.md\n",
        );

        self::assertSame([], $violations);
    }

    /**
     * A message that starts one blank line low is stored by Git with a perfectly valid
     * header, so the hook must not reject it as headerless.
     */
    public function testALeadingBlankLineIsNotAnEmptyHeader(): void
    {
        $violations = (new CommitMessageValidator($this->config()))->validate(
            "\n\n[+]: \"scope\" -> subject\n\nWHY:\n- TICKET-42 blocked the nightly sync\n",
        );

        self::assertSame([], $violations);
    }

    /** A message that really is only commentary has no header at all. */
    public function testAMessageOfNothingButCommentsIsStillAnEmptyHeader(): void
    {
        $violations = (new CommitMessageValidator($this->config()))->validate(
            "# Please enter the commit message for your changes.\n#\n# On branch main\n",
        );

        self::assertNotSame([], $violations);
        self::assertStringContainsString('Empty commit header', $violations[0]);
    }

    /** Only a `#` in the first column is a comment to Git, so an indented one is body content. */
    public function testAnIndentedHashIsBodyContentBecauseGitKeepsIt(): void
    {
        $violations = (new CommitMessageValidator($this->config()))->validate(
            "[+]: \"scope\" -> subject\n\nWHY:\n  # TICKET-42 blocked the nightly sync\n",
        );

        self::assertSame([], $violations);
    }

    /** Trailing commentary must not count as the body that the WHY section needs. */
    public function testACommentOnlyBodyDoesNotSatisfyTheRequiredSection(): void
    {
        $violations = (new CommitMessageValidator($this->config()))->validate(
            "[+]: \"scope\" -> subject\n\n# WHY: [FILL]\n# explain the change\n",
        );

        self::assertNotSame([], $violations);
        self::assertStringContainsString('Missing commit body', $violations[0]);
    }

    private function config(): GitHookConfig
    {
        return GitHookConfig::fromArray([
            'commit_msg' => [
                'header_pattern' => '/^\[(\+|~|!|\*|!!!)\]:\s+[^ ].*\s+->\s+.+$/',
                'header_hint' => '[SYMBOL]: "scope" -> <subject>',
                'trivial_header_pattern' => '/^\[\*\]:/',
                'forbidden_patterns' => [
                    ['pattern' => '/WHY:\s*\[FILL\]/i', 'message' => 'Commit template placeholder found (WHY: [FILL]).'],
                ],
                'required_section' => 'WHY',
                'vague_phrases' => ['cleanup'],
                'vague_word_threshold' => 8,
            ],
        ]);
    }

    private function initRepository(): void
    {
        $this->git('init --quiet');
        $this->git('config user.email agent-loop@example.test');
        $this->git('config user.name agent-loop');
    }

    private function commitThroughEditor(string $message): void
    {
        $editor = $this->root . '/editor.sh';
        file_put_contents(
            $editor,
            "#!/bin/sh\ncat " . escapeshellarg($this->root . '/typed.txt') . " \"\$1\" > \"\$1.tmp\" && mv \"\$1.tmp\" \"\$1\"\n",
        );
        chmod($editor, 0o775);
        file_put_contents($this->root . '/typed.txt', $message);

        exec(
            'GIT_EDITOR=' . escapeshellarg($editor)
            . ' git -C ' . escapeshellarg($this->root) . ' commit --quiet 2>/dev/null',
            $output,
            $exitCode,
        );
        self::assertSame(0, $exitCode, 'the fixture commit must succeed: ' . implode("\n", $output));
    }

    private function git(string $arguments): string
    {
        $output = [];
        exec('git -C ' . escapeshellarg($this->root) . ' ' . $arguments . ' 2>/dev/null', $output);

        return implode("\n", $output);
    }
}
