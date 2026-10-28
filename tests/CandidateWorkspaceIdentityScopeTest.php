<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\CandidateWorkspaceIdentity;

/**
 * In a shared checkout other tasks keep uncommitted files in the worktree. They are not part of the governed
 * candidate, so verify must hold when the approved scope matches, and must still fail when scope content differs.
 */
final class CandidateWorkspaceIdentityScopeTest extends TestCase
{
    private string $root;
    private string $base;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-identity-scope-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/other', 0777, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\n");
        file_put_contents($this->root . '/other/Bar.php', "<?php\n");
        $this->git(['init', '-q']);
        $this->git(['add', '-A']);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.org', 'commit', '-q', '-m', 'base']);
        $this->base = trim($this->git(['rev-parse', 'HEAD']));
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testDirtyFileOutsideTheScopeDoesNotBreakTheIdentity(): void
    {
        file_put_contents($this->root . '/other/Bar.php', "<?php\n// another task\n");
        file_put_contents($this->root . '/other/New.php', "<?php\n");

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src/Foo.php']);

        $this->addToAssertionCount(1);
    }

    public function testForeignFilesNeverEnterTheObservedCandidate(): void
    {
        $identity = new CandidateWorkspaceIdentity();
        $clean = $identity->observe($this->root, $this->base, null, ['src/Foo.php']);

        file_put_contents($this->root . '/other/Bar.php', "<?php\n// another task\n");
        file_put_contents($this->root . '/other/New.php', "<?php\n");

        self::assertSame($clean, $identity->observe($this->root, $this->base, null, ['src/Foo.php']));
        self::assertNotSame($clean, $identity->observe($this->root, $this->base), 'The unscoped observation still sees the whole tree.');
        self::assertTrue($identity->differsOutsideScope($this->root, $this->base, null, ['src/Foo.php']));
    }

    public function testScopeEntryThatExistsNowhereContributesNothing(): void
    {
        $identity = new CandidateWorkspaceIdentity();

        self::assertSame(
            $identity->observe($this->root, $this->base, null, ['src/Foo.php']),
            $identity->observe($this->root, $this->base, null, ['src/Foo.php', 'src/NotCreatedYet.php']),
        );
    }

    /** @return iterable<string, array{list<string>, list<string>}> */
    public static function contractScopeSpellings(): iterable
    {
        yield 'trailing slash' => [['src/'], ['src']];
        yield 'leading dot slash' => [['./src/Foo.php'], ['src/Foo.php']];
        yield 'backslashes' => [['src\\Foo.php'], ['src/Foo.php']];
        yield 'duplicates' => [['src', 'src/'], ['src']];
        yield 'whole repository' => [['src', '.'], []];
        yield 'absolute stays refusable' => [['/etc'], ['/etc']];
    }

    /**
     * @param list<string> $spelled
     * @param list<string> $expected
     */
    #[DataProvider('contractScopeSpellings')]
    public function testHandWrittenContractScopeSpellingsAreNormalized(array $spelled, array $expected): void
    {
        self::assertSame($expected, CandidateWorkspaceIdentity::normalizeScope($spelled));
    }

    public function testNormalizedTrailingSlashDirectoryStillDetectsAnInScopeChange(): void
    {
        file_put_contents($this->root . '/src/Extra.php', "<?php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, CandidateWorkspaceIdentity::normalizeScope(['src/']));
    }

    public function testIgnoredScopePathContributesNothingInsteadOfFailingGit(): void
    {
        file_put_contents($this->root . '/.gitignore', "ignored/\n");
        mkdir($this->root . '/ignored', 0777, true);
        file_put_contents($this->root . '/ignored/x.txt', 'local only');
        $identity = new CandidateWorkspaceIdentity();

        self::assertSame(
            $identity->observe($this->root, $this->base, null, ['src/Foo.php']),
            $identity->observe($this->root, $this->base, null, ['src/Foo.php', 'ignored/x.txt', 'ignored']),
        );
    }

    public function testWithoutAScopeAnyDirtyFileStillBreaksTheIdentity(): void
    {
        file_put_contents($this->root . '/other/Bar.php', "<?php\n// another task\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base);
    }

    public function testDirtyFileInsideTheScopeStillBreaksTheIdentity(): void
    {
        file_put_contents($this->root . '/src/Foo.php', "<?php\n// changed after the candidate\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src/Foo.php']);
    }

    public function testNewFileInsideAScopeDirectoryStillBreaksTheIdentity(): void
    {
        file_put_contents($this->root . '/src/Extra.php', "<?php\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src']);
    }

    public function testMagicPathspecEntriesCannotHideAnInScopeChange(): void
    {
        file_put_contents($this->root . '/src/Foo.php', "<?php\n// changed after the candidate\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src', ':(exclude)src/Foo.php']);
    }

    public function testGlobCharactersInAScopeEntryNeverWidenOrNarrowTheScope(): void
    {
        file_put_contents($this->root . '/other/Bar.php', "<?php\n// another task\n");

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['*', 'src/*.php']);

        $this->addToAssertionCount(1);
    }

    public function testLiteralScopeEntryWithGlobCharactersStillDetectsItsOwnChange(): void
    {
        file_put_contents($this->root . '/src/Foo[1].php', "<?php\n");
        $this->git(['add', '-A']);
        $this->git(['-c', 'user.name=t', '-c', 'user.email=t@example.org', 'commit', '-q', '-m', 'bracket file']);
        $base = trim($this->git(['rev-parse', 'HEAD']));
        file_put_contents($this->root . '/src/Foo[1].php', "<?php\n// changed\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $base, $base, null, ['src/Foo[1].php']);
    }

    public function testDeletingAnInScopeFileBreaksTheIdentity(): void
    {
        unlink($this->root . '/src/Foo.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src/Foo.php']);
    }

    public function testMovingAnInScopeFileOutOfTheScopeBreaksTheIdentity(): void
    {
        rename($this->root . '/src/Foo.php', $this->root . '/other/Foo.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src']);
    }

    public function testReplacingAnInScopeFileWithASymlinkBreaksTheIdentity(): void
    {
        unlink($this->root . '/src/Foo.php');
        symlink('../other/Bar.php', $this->root . '/src/Foo.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src/Foo.php']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeScopeEntries(): iterable
    {
        yield 'empty' => [''];
        yield 'absolute' => ['/etc'];
        yield 'parent segment' => ['../other'];
        yield 'dot segment' => ['src/./Foo.php'];
        yield 'nul byte' => ["src/Foo.php\0"];
    }

    #[DataProvider('unsafeScopeEntries')]
    public function testUnsafeScopeEntriesAreRefusedInsteadOfNarrowingTheCheck(string $entry): void
    {
        $this->expectException(RuntimeException::class);

        (new CandidateWorkspaceIdentity())->assertHolds($this->root, $this->base, $this->base, null, ['src/Foo.php', $entry]);
    }

    /** @param non-empty-list<string> $arguments */
    private function git(array $arguments): string
    {
        $command = 'git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1';
        exec($command, $output, $exitCode);
        self::assertSame(0, $exitCode, $command . "\n" . implode("\n", $output));

        return implode("\n", $output);
    }
}
