<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Execution\CandidateWorkspaceIdentity;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Tests\Support\VerifyStageRunFixture;

/**
 * In a checkout shared with other tasks, verify judges the accepted candidate on its approved scope and runs the
 * declared validation against an exported copy of that candidate, so foreign uncommitted files can neither block
 * a correct candidate nor make a failing one pass (#858).
 */
final class CandidateScopeExportTest extends TestCase
{
    private const GOOD = "<?php\nfinal class Foo { public const int X = 1; }\n";

    private VerifyStageRunFixture $run;

    protected function setUp(): void
    {
        $this->run = new VerifyStageRunFixture();
    }

    protected function tearDown(): void
    {
        $this->run->remove();
    }

    public function testForeignFileCannotTurnAFailingCandidateIntoAPassingVerify(): void
    {
        $this->acceptCandidate(self::GOOD, ['php -r "exit(is_file(\'helper.txt\') ? 0 : 1);"']);
        file_put_contents($this->run->root . '/helper.txt', 'another task left this here');

        $projection = $this->verify();

        self::assertFalse($projection->complete());
        self::assertNotNull($projection->attention);
        self::assertStringContainsString('[FAIL]', $projection->attention->message);
    }

    public function testForeignFilesOutsideTheScopeDoNotBlockACorrectCandidate(): void
    {
        $this->acceptCandidate(self::GOOD, ['php -l src/Foo.php']);
        file_put_contents($this->run->root . '/notes-of-another-task.txt', 'work in progress');
        file_put_contents($this->run->root . '/prepare.php', "<?php\n// edited by another task\n");

        $projection = $this->verify();

        self::assertTrue($projection->complete());
        self::assertNull($projection->attention);
    }

    public function testScopedFileChangedAfterAcceptanceStillBlocksVerify(): void
    {
        $this->acceptCandidate(self::GOOD, ['php -l src/Foo.php']);
        file_put_contents($this->run->root . '/src/Foo.php', "<?php\nfinal class Foo { public const int X = 2; }\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        $this->verify();
    }

    public function testCleanOwnerCheckoutKeepsValidatingInPlace(): void
    {
        $this->acceptCandidate(self::GOOD, [$this->workingDirectoryIs($this->run->root, true)]);

        $projection = $this->verify();

        self::assertTrue($projection->complete(), 'Without foreign files the declared validation runs in the checkout as before.');
    }

    public function testForeignFilesMoveValidationIntoAnExportThatIsRemovedAfterwards(): void
    {
        $this->acceptCandidate(self::GOOD, [$this->workingDirectoryIs($this->run->root, false), 'php -r "file_put_contents(\'validation-output.txt\', \'x\');"']);
        file_put_contents($this->run->root . '/notes-of-another-task.txt', 'work in progress');
        $before = glob(sys_get_temp_dir() . '/agent-loop-candidate-export-*') ?: [];

        $projection = $this->verify();

        self::assertTrue($projection->complete(), (string) $projection->attention?->message);
        self::assertFileDoesNotExist($this->run->root . '/validation-output.txt', 'Validation output stays out of the shared checkout.');
        self::assertSame($before, glob(sys_get_temp_dir() . '/agent-loop-candidate-export-*') ?: [], 'The export directory is removed.');
    }

    public function testExportIsRemovedEvenWhenValidationLeavesAnUnreadableDirectory(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('Directory permissions do not restrict root.');
        }
        $this->acceptCandidate(self::GOOD, ['php -r "mkdir(\'locked\'); file_put_contents(\'locked/data.txt\', \'x\'); chmod(\'locked\', 0);"']);
        file_put_contents($this->run->root . '/notes-of-another-task.txt', 'work in progress');
        $before = glob(sys_get_temp_dir() . '/agent-loop-candidate-export-*') ?: [];

        try {
            $projection = $this->verify();
        } finally {
            $leaked = array_values(array_diff(glob(sys_get_temp_dir() . '/agent-loop-candidate-export-*') ?: [], $before));
            foreach ($leaked as $directory) {
                exec('chmod -R u+rwx ' . escapeshellarg($directory) . ' && rm -rf ' . escapeshellarg($directory));
            }
        }
        self::assertSame([], $leaked, 'The export (a copy of tracked sources) must not outlive verify.');
        self::assertTrue($projection->complete(), (string) $projection->attention?->message);
    }

    public function testForeignEditWhileValidationRunsInTheExportDoesNotBlockVerify(): void
    {
        $this->acceptCandidate(self::GOOD, [sprintf('php -r "file_put_contents(\'%s/late-foreign.txt\', \'x\');"', $this->run->root)]);
        file_put_contents($this->run->root . '/notes-of-another-task.txt', 'work in progress');

        $projection = $this->verify();

        self::assertTrue($projection->complete(), (string) $projection->attention?->message);
    }

    public function testExportCandidatePreservesTrackedSymlinksAndExecutableModes(): void
    {
        $source = sys_get_temp_dir() . '/agent-loop-export-source-' . bin2hex(random_bytes(8));
        $export = sys_get_temp_dir() . '/agent-loop-export-copy-' . bin2hex(random_bytes(8));

        try {
            self::assertTrue(mkdir($source, 0o775, true));
            self::assertTrue(mkdir($export, 0o775, true));
            self::assertNotFalse(file_put_contents($source . '/target.txt', 'target'));
            self::assertNotFalse(file_put_contents($source . '/run.sh', "#!/bin/sh\nexit 0\n"));
            self::assertTrue(chmod($source . '/run.sh', 0o755));
            if (!@symlink('target.txt', $source . '/link.txt')) {
                self::markTestSkipped('Symbolic links are unavailable.');
            }

            $this->run->git($source, 'init', '-q');
            $this->run->git($source, 'config', 'user.name', 'Evidence Lab');
            $this->run->git($source, 'config', 'user.email', 'evidence@example.invalid');
            $this->run->git($source, 'add', 'target.txt', 'link.txt', 'run.sh');
            $this->run->git($source, 'commit', '-qm', 'candidate');
            $base = $this->run->git($source, 'rev-parse', 'HEAD');

            $identity = new CandidateWorkspaceIdentity();
            $candidate = $identity->observe($source, $base);
            $identity->exportCandidate($source, $base, $candidate, $export);

            self::assertTrue(is_link($export . '/link.txt'));
            self::assertSame('target.txt', readlink($export . '/link.txt'));
            $mode = fileperms($export . '/run.sh');
            self::assertIsInt($mode);
            self::assertNotSame(0, $mode & 0o111);
        } finally {
            exec('rm -rf ' . escapeshellarg($source) . ' ' . escapeshellarg($export));
        }
    }

    public function testValidationThatWritesIntoTheCheckoutInPlaceIsStillRefused(): void
    {
        $this->acceptCandidate(self::GOOD, ['php -r "file_put_contents(\'side-effect.txt\', \'x\');"']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        $this->verify();
    }

    public function testFailureInTheExportSaysItRanAgainstAnExport(): void
    {
        $this->acceptCandidate(self::GOOD, ['php -r "exit(1);"']);
        file_put_contents($this->run->root . '/notes-of-another-task.txt', 'work in progress');

        $projection = $this->verify();

        self::assertNotNull($projection->attention);
        self::assertStringContainsString('[export]', $projection->attention->message);
        self::assertStringContainsString('declare their preparation', $projection->attention->message);
    }

    public function testExplicitCandidateWorktreeKeepsTheExactWholeTreeIdentity(): void
    {
        $this->acceptCandidate(self::GOOD, ['php -l src/Foo.php']);
        file_put_contents($this->run->workspace . '/notes-of-another-task.txt', 'work in progress');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');

        (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify', $this->run->workspace);
    }

    /** @param list<string> $validation */
    private function acceptCandidate(string $source, array $validation): void
    {
        $this->run->reachVerifyWith($source, $validation);
        // The shared checkout holds the accepted change in place, like a host-native builder leaves it.
        file_put_contents($this->run->root . '/src/Foo.php', $source);
    }

    private function verify(): \voku\AgentLoop\Execution\ExecutionProjection
    {
        return (new ExecutionGateway($this->run->root))->runDeterministicStage('VER-1', 'verify');
    }

    private function workingDirectoryIs(string $directory, bool $expected): string
    {
        return sprintf(
            'php -r "exit((realpath(getcwd()) === realpath(\'%s\')) === %s ? 0 : 1);"',
            $directory,
            $expected ? 'true' : 'false',
        );
    }
}
