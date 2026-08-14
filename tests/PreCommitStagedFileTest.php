<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\GitHooks\GitHookConfig;
use voku\AgentLoop\GitHooks\PreCommitRunner;

/**
 * What the pre-commit gate actually sees.
 *
 * A filter that drops a file it does not recognise fails quietly: the checks never
 * run on it, and the hook still exits 0. That is worse than a crash, because the
 * commit lands looking checked. These tests drive a real repository and a real
 * `git diff --cached` so the file list is Git's, not a stub's.
 *
 * @internal
 */
final class PreCommitStagedFileTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-precommit-staged-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        exec('git -C ' . escapeshellarg($this->root) . ' init --quiet 2>/dev/null');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    /**
     * `core.quotePath` is on by default, so Git prints any path with a non-ASCII byte
     * C-quoted: `für.php` comes back as the literal ten-character string
     * `"f\303\274r.php"`. That matches no `*.php` pattern and names no file on disk,
     * so the file dropped out of the batch and every configured check skipped it.
     */
    public function testAFileWithANonAsciiNameIsStillChecked(): void
    {
        $this->stage('src/für.php', "<?php\n");
        $this->stage('src/Plain.php', "<?php\n");

        self::assertSame(['src/Plain.php', 'src/für.php'], $this->stagedFiles());
    }

    /**
     * `core.quotePath=false` covers only the non-ASCII half of the munging. A tab, a
     * double quote or a newline in a pathname is C-quoted regardless of that setting,
     * so the same silent skip survives for every control character unless the path
     * list is read the way Git means it to be read.
     *
     * @param non-empty-string $name
     */
    #[DataProvider('mungedNames')]
    public function testAFileWhoseNameGitWouldQuoteIsStillChecked(string $name): void
    {
        $this->stage('src/' . $name, "<?php\n");

        self::assertSame(['src/' . $name], $this->stagedFiles());
    }

    /** @return array<string, array{non-empty-string}> */
    public static function mungedNames(): array
    {
        return [
            'non-ascii' => ['für.php'],
            'tab' => ["tab\tbed.php"],
            'double quote' => ['quo"te.php'],
            'newline' => ["we\nird.php"],
            'backslash' => ['back\\slash.php'],
        ];
    }

    /** Several munged names in one commit must all survive, and stay separate paths. */
    public function testEveryMungedNameInOneCommitSurvivesAsItsOwnPath(): void
    {
        $this->stage("src/we\nird.php", "<?php\n");
        $this->stage("src/tab\tbed.php", "<?php\n");
        $this->stage('src/für.php', "<?php\n");

        $files = $this->stagedFiles();
        sort($files, SORT_STRING);

        self::assertSame(['src/für.php', "src/tab\tbed.php", "src/we\nird.php"], $files);
    }

    /** A quoted path must not survive as its quoted spelling either. */
    public function testTheQuotedSpellingNeverReachesTheCheckCommand(): void
    {
        $this->stage('src/für.php', "<?php\n");
        $this->stage("src/tab\tbed.php", "<?php\n");

        $files = $this->stagedFiles();
        self::assertCount(2, $files);
        foreach ($files as $file) {
            self::assertStringNotContainsString('\\303', $file);
            self::assertStringNotContainsString('\\t', $file);
            self::assertDoesNotMatchRegularExpression('/\A".*"\z/', $file);
            self::assertFileExists($this->root . '/' . $file);
        }
    }

    /** Names Git does not quote must keep working exactly as before. */
    public function testOrdinaryAndSpaceContainingNamesAreUnaffected(): void
    {
        $this->stage('src/Plain.php', "<?php\n");
        $this->stage('src/spa ce.php', "<?php\n");
        $this->stage('README.md', "docs\n");

        self::assertSame(['src/Plain.php', 'src/spa ce.php'], $this->stagedFiles());
    }

    /**
     * The very first commit has no HEAD to diff against, and the fallback command must
     * carry the same quoting flag as the one it replaces.
     */
    public function testTheNoHeadFallbackAlsoSeesTheRealPath(): void
    {
        $this->stage('src/für.php', "<?php\n");

        // Nothing has been committed yet, so `diff --cached ... HEAD` fails and the
        // runner falls back to the HEAD-less form.
        self::assertSame([], $this->git('rev-parse -q --verify HEAD'));
        self::assertSame(['src/für.php'], $this->stagedFiles());
    }

    /** @return list<string> */
    private function stagedFiles(): array
    {
        $runner = new PreCommitRunner($this->root, GitHookConfig::fromArray([
            'pre_commit' => ['file_patterns' => ['*.php']],
        ]));

        return $runner->stagedFiles(static function (string $command): array {
            $output = [];
            $exitCode = 0;
            exec($command . ' 2>/dev/null', $output, $exitCode);

            return ['output' => $output, 'exit' => $exitCode];
        });
    }

    private function stage(string $relativePath, string $contents): void
    {
        file_put_contents($this->root . '/' . $relativePath, $contents);
        exec(
            'git -C ' . escapeshellarg($this->root) . ' add ' . escapeshellarg($relativePath) . ' 2>/dev/null',
            $output,
            $exitCode,
        );
        self::assertSame(0, $exitCode, 'staging the fixture must succeed: ' . $relativePath);
    }

    /** @return list<string> */
    private function git(string $arguments): array
    {
        $output = [];
        exec('git -C ' . escapeshellarg($this->root) . ' ' . $arguments . ' 2>/dev/null', $output);

        return $output;
    }
}
