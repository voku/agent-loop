<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\Transparency\RepositoryObservationCollector;
use voku\AgentLoop\Workflow\Transparency\RepositoryObservationStatus;

final class TransparencyRepositoryObservationTest extends TestCase
{
    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testSeparatesCommittedStagedUnstagedAndUntrackedChanges(): void
    {
        $this->requireGit();
        $root = $this->repository();

        file_put_contents($root . '/base.txt', "base\n");
        file_put_contents($root . '/staged.txt', "staged before\n");
        file_put_contents($root . '/unstaged.txt', "unstaged before\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-m', 'base']);
        $base = trim($this->git($root, ['rev-parse', 'HEAD']));

        file_put_contents($root . '/committed.txt', "committed after base\n");
        $this->git($root, ['add', 'committed.txt']);
        $this->git($root, ['commit', '-m', 'after base']);

        file_put_contents($root . '/staged.txt', "staged after\n");
        $this->git($root, ['add', 'staged.txt']);
        file_put_contents($root . '/unstaged.txt', "unstaged after\n");
        file_put_contents($root . '/untracked.txt', "untracked\n");

        $observation = (new RepositoryObservationCollector($root))->collect($this->contract($base, ['.']));

        self::assertSame(RepositoryObservationStatus::OBSERVED, $observation->status);
        self::assertTrue($observation->isObserved());
        self::assertSame($base, $observation->baseCommit);
        self::assertSame(['committed.txt'], $observation->committed);
        self::assertSame(['staged.txt'], $observation->staged);
        self::assertSame(['unstaged.txt'], $observation->unstaged);
        self::assertSame(['untracked.txt'], $observation->untracked);
        self::assertSame(
            ['committed.txt', 'staged.txt', 'unstaged.txt', 'untracked.txt'],
            $observation->changedFiles,
        );
        self::assertNotSame($base, $observation->headCommit);
    }

    /**
     * Git's default output would quote and escape these names into strings that
     * no longer address the files. `-z` is the only reader that survives them.
     */
    public function testHostileFileNamesSurviveObservationUnchanged(): void
    {
        $this->requireGit();
        $root = $this->repository();

        file_put_contents($root . '/seed.txt', "seed\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-m', 'seed']);
        $base = trim($this->git($root, ['rev-parse', 'HEAD']));

        $spaced = 'a file with spaces.txt';
        $newlined = "a file with\na newline.txt";
        $quoted = 'a "quoted" file.txt';
        $unicode = 'ünïcödé-file.txt';
        foreach ([$spaced, $newlined, $quoted, $unicode] as $name) {
            file_put_contents($root . '/' . $name, "content\n");
        }

        $observation = (new RepositoryObservationCollector($root))->collect($this->contract($base, ['.']));

        self::assertTrue($observation->isObserved());
        foreach ([$spaced, $newlined, $quoted, $unicode] as $name) {
            self::assertContains($name, $observation->untracked, 'lost: ' . json_encode($name));
            self::assertContains($name, $observation->changedFiles);
        }
    }

    public function testMissingBaseCommitIsTypedUnavailableRatherThanAnEmptyChangeSet(): void
    {
        $this->requireGit();
        $root = $this->repository();
        file_put_contents($root . '/seed.txt', "seed\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-m', 'seed']);
        file_put_contents($root . '/changed.txt', "changed\n");

        $contract = $this->contract('0000000000000000000000000000000000000000', ['.']);
        $observation = (new RepositoryObservationCollector($root))->collect($contract);

        self::assertSame(RepositoryObservationStatus::BASE_COMMIT_UNKNOWN, $observation->status);
        self::assertFalse($observation->isObserved());
        self::assertSame([], $observation->changedFiles);
        self::assertNotNull($observation->unavailableReason);
    }

    public function testContractWithoutBaseCommitIsUnavailableAndNeverGuessesOne(): void
    {
        $this->requireGit();
        $root = $this->repository();
        file_put_contents($root . '/seed.txt', "seed\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-m', 'seed']);

        $observation = (new RepositoryObservationCollector($root))->collect($this->contract(null, ['.']));

        self::assertSame(RepositoryObservationStatus::NO_BASE_COMMIT, $observation->status);
        self::assertNull($observation->baseCommit);
        self::assertSame([], $observation->changedFiles);
    }

    public function testMissingContractIsUnavailableRatherThanAnUnscopedObservation(): void
    {
        $root = $this->tempDir();

        $observation = (new RepositoryObservationCollector($root))->collect(null);

        self::assertSame(RepositoryObservationStatus::NO_CONTRACT, $observation->status);
        self::assertSame([], $observation->changedFiles);
    }

    public function testDirectoryOutsideAGitWorkTreeIsTypedUnavailable(): void
    {
        $this->requireGit();
        $root = $this->tempDir();

        $observation = (new RepositoryObservationCollector($root))
            ->collect($this->contract('0000000000000000000000000000000000000000', ['.']));

        self::assertSame(RepositoryObservationStatus::NOT_A_GIT_WORK_TREE, $observation->status);
    }

    /** Reading transparency must never change what the next read observes. */
    public function testObservationWritesNothing(): void
    {
        $this->requireGit();
        $root = $this->repository();
        file_put_contents($root . '/seed.txt', "seed\n");
        $this->git($root, ['add', '.']);
        $this->git($root, ['commit', '-m', 'seed']);
        $base = trim($this->git($root, ['rev-parse', 'HEAD']));
        file_put_contents($root . '/staged.txt', "staged\n");
        $this->git($root, ['add', 'staged.txt']);
        file_put_contents($root . '/untracked.txt', "untracked\n");

        $statusBefore = $this->git($root, ['status', '--porcelain=v1', '-z', '--untracked-files=all']);
        $headBefore = trim($this->git($root, ['rev-parse', 'HEAD']));

        $collector = new RepositoryObservationCollector($root);
        $first = $collector->collect($this->contract($base, ['.']));
        $second = $collector->collect($this->contract($base, ['.']));

        self::assertSame($first->changedFiles, $second->changedFiles);
        self::assertSame($statusBefore, $this->git($root, ['status', '--porcelain=v1', '-z', '--untracked-files=all']));
        self::assertSame($headBefore, trim($this->git($root, ['rev-parse', 'HEAD'])));
    }

    /** @param list<string> $scope */
    private function contract(?string $baseCommit, array $scope): TaskContract
    {
        return new TaskContract(
            taskId: 'OBSERVE-1',
            goal: 'Observe repository change.',
            scope: $scope,
            nonGoals: [],
            validation: [],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-08-25T00:00:00+00:00',
            updatedAt: '2026-08-25T00:00:00+00:00',
            path: '/tmp/contract.json',
            plannedBy: 'planner',
            baseCommit: $baseCommit,
            approvedBy: 'approver',
            approvedAt: '2026-08-25T00:00:00+00:00',
        );
    }

    private function requireGit(): void
    {
        if ($this->process(getcwd() ?: '.', ['git', '--version'])['exit'] !== 0) {
            self::markTestSkipped('Git is required for repository observation coverage.');
        }
    }

    private function repository(): string
    {
        $root = $this->tempDir();
        $this->git($root, ['init']);
        $this->git($root, ['config', 'user.email', 'observe@example.test']);
        $this->git($root, ['config', 'user.name', 'Observation Test']);
        $this->git($root, ['config', 'commit.gpgsign', 'false']);

        return $root;
    }

    /** @param list<string> $args */
    private function git(string $root, array $args): string
    {
        $result = $this->process($root, ['git', ...$args]);
        self::assertSame(0, $result['exit'], $result['stderr']);

        return $result['stdout'];
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function process(string $root, array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/agent-loop-transparency-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o777, true));
        $this->tempDirs[] = $dir;

        return $dir;
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->removeDirectory($path);
            }
        }
        rmdir($dir);
    }
}
