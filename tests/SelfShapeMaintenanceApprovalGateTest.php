<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Dogfood\SelfShapeGitChangedPaths;
use voku\AgentLearning\AppliedGuidanceMaintenanceEvidence;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceApprovalGate;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceReviewClaim;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceReviewCommentEvidence;

/** @internal */
final class SelfShapeMaintenanceApprovalGateTest extends TestCase
{
    private const string BASE = '622c004e2cc642fce1c06dc63a4f04d4889637e2';
    private const string HEAD = 'f1d1f7b91a7adad974aa88ca5104135894d43196';
    private const string BEFORE = '0f6fc7f561e435f8e9e3e53cf3fc8cc284175ed80b38211880271282c911564a';
    private const string AFTER = '7a9afab8ec812280ae036e6afd901d6c8d7e0f35bc3439197a547f886926fa0e';

    public function testAcceptsExactMaintenanceOnlyWithTheTwoAppliedProofFiles(): void
    {
        self::assertTrue($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            $this->expectedPaths(),
        ));
    }

    public function testExtraProductionChangeIsNotDisguisedAsMemoryMaintenance(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            [...$this->expectedPaths(), 'src/Dispatcher.php'],
        ));
    }

    public function testMissingAppliedProofChangeCannotPass(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            ['MEMORY.md', '.agent-loop/learning/proposals/applied/proposal.2026-08-14.007.json'],
        ));
    }

    public function testStaleOrTamperedFullDiffIsNotApproved(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'other diff'),
            $this->expectedPaths(),
        ));
    }

    public function testDuplicateOrForeignChangedPathIsRejected(): void
    {
        self::assertFalse($this->gate()->matches(
            $this->owner(),
            $this->review(),
            self::BASE,
            self::HEAD,
            hash('sha256', 'complete diff'),
            [...$this->expectedPaths(), 'MEMORY.md'],
        ));
    }

    public function testActualGitDeletionAndRenameCannotDisappearFromScope(): void
    {
        $directory = sys_get_temp_dir() . '/self-shape-git-scope-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory . '/src', 0700, true));
        self::assertTrue(mkdir($directory . '/.agent-loop/learning/proposals/applied', 0700, true));

        $runner = new ProcessRunner($directory);
        $gitEnvironment = array_filter(getenv(), static fn (string $key): bool => !str_starts_with($key, 'GIT_'), ARRAY_FILTER_USE_KEY);
        $gitEnvironment['GIT_CONFIG_NOSYSTEM'] = '1';
        $gitEnvironment['GIT_CONFIG_GLOBAL'] = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        try {
            foreach ([...$this->expectedPaths(), 'src/Deleted.php', 'src/Old name .php'] as $path) {
                self::assertNotFalse(file_put_contents($directory . '/' . $path, 'before'));
            }
            $runner->mustRun(['git', 'init', '--quiet'], $gitEnvironment);
            $runner->mustRun(['git', 'add', '--all'], $gitEnvironment);
            $runner->mustRun(['git', '-c', 'user.name=Scope Test', '-c', 'user.email=scope@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'baseline'], $gitEnvironment);
            $base = trim($runner->mustRun(['git', 'rev-parse', 'HEAD'], $gitEnvironment)['stdout']);

            foreach ($this->expectedPaths() as $path) {
                self::assertNotFalse(file_put_contents($directory . '/' . $path, 'after'));
            }
            self::assertTrue(unlink($directory . '/src/Deleted.php'));
            self::assertTrue(rename($directory . '/src/Old name .php', $directory . '/src/New name .php'));
            $runner->mustRun(['git', 'add', '--all'], $gitEnvironment);
            $runner->mustRun(['git', '-c', 'user.name=Scope Test', '-c', 'user.email=scope@example.invalid', '-c', 'commit.gpgsign=false', 'commit', '--quiet', '-m', 'changes'], $gitEnvironment);
            $head = trim($runner->mustRun(['git', 'rev-parse', 'HEAD'], $gitEnvironment)['stdout']);

            $scope = (new SelfShapeGitChangedPaths($runner))->between($base, $head);

            $expected = [
                ...$this->expectedPaths(),
                'src/Deleted.php',
                'src/Old name .php',
                'src/New name .php',
            ];
            sort($expected);
            sort($scope);
            self::assertSame($expected, $scope);
            self::assertFalse($this->gate()->matches(
                $this->owner(),
                $this->review(),
                self::BASE,
                self::HEAD,
                hash('sha256', 'complete diff'),
                $scope,
            ));
        } finally {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iterator as $item) {
                if ($item->isDir() && !$item->isLink()) {
                    rmdir($item->getPathname());
                } else {
                    unlink($item->getPathname());
                }
            }
            rmdir($directory);
        }
    }

    private function gate(): SelfShapeMaintenanceApprovalGate
    {
        return new SelfShapeMaintenanceApprovalGate();
    }

    private function review(): SelfShapeMaintenanceReviewCommentEvidence
    {
        return new SelfShapeMaintenanceReviewCommentEvidence(
            new SelfShapeMaintenanceReviewClaim(
                'MEMORY.md',
                self::BASE,
                self::HEAD,
                self::BEFORE,
                self::AFTER,
                hash('sha256', 'complete diff'),
            ),
            'voku',
            123456,
            'https://github.com/voku/agent-loop/pull/758#issuecomment-123456',
        );
    }

    private function owner(): AppliedGuidanceMaintenanceEvidence
    {
        return new AppliedGuidanceMaintenanceEvidence(
            'MEMORY.md',
            self::BEFORE,
            self::AFTER,
            ['proposal.2026-08-14.007', 'proposal.2026-08-14.013'],
            [
                'proposal.2026-08-14.007' => [
                    'actor' => 'voku',
                    'at' => '2026-10-10T19:33:03+00:00',
                    'reason' => 'correct canonical home',
                ],
                'proposal.2026-08-14.013' => [
                    'actor' => 'voku',
                    'at' => '2026-10-10T19:33:03+00:00',
                    'reason' => 'correct canonical home',
                ],
            ],
        );
    }

    /** @return list<string> */
    private function expectedPaths(): array
    {
        return [
            '.agent-loop/learning/proposals/applied/proposal.2026-08-14.007.json',
            '.agent-loop/learning/proposals/applied/proposal.2026-08-14.013.json',
            'MEMORY.md',
        ];
    }
}
