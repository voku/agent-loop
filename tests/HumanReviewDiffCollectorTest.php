<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\HumanReviewDiffCollector;
use voku\AgentLoop\Workflow\TaskContract;

final class HumanReviewDiffCollectorTest extends TestCase
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

    public function testCollectsTrackedAndUntrackedChangesFromContractBaseCommit(): void
    {
        if (!$this->gitAvailable()) {
            self::markTestSkipped('Git is required for review-diff integration coverage.');
        }

        $root = $this->tempDir();
        $this->git($root, ['init']);
        $this->git($root, ['config', 'user.email', 'review@example.test']);
        $this->git($root, ['config', 'user.name', 'Review Test']);
        file_put_contents($root . '/README.md', "before\n");
        $this->git($root, ['add', 'README.md']);
        $this->git($root, ['commit', '-m', 'base']);
        $base = trim($this->git($root, ['rev-parse', 'HEAD']));

        file_put_contents($root . '/README.md', "after <tracked>\n");
        mkdir($root . '/src');
        file_put_contents($root . '/src/New.php', "<?php\n\necho '<untracked>';\n");

        $contract = new TaskContract(
            taskId: 'REVIEW-1',
            goal: 'Review bounded changes.',
            scope: ['README.md', 'src'],
            nonGoals: [],
            validation: ['composer test'],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-08-20T20:00:00+00:00',
            updatedAt: '2026-08-20T20:00:00+00:00',
            path: $root . '/contract.json',
            plannedBy: 'planner',
            baseCommit: $base,
            approvedBy: 'reviewer',
            approvedAt: '2026-08-20T20:00:00+00:00',
        );

        $diff = (new HumanReviewDiffCollector($root))->collect($contract);

        self::assertTrue($diff->available, $diff->unavailableReason ?? 'diff unavailable');
        self::assertSame($base, $diff->baseCommit);
        self::assertSame(['README.md', 'src/New.php'], $diff->changedFiles);
        self::assertSame(['src/New.php'], $diff->untrackedFiles);
        self::assertStringContainsString('after <tracked>', $diff->patch);
        self::assertStringContainsString('new file mode untracked', $diff->patch);
        self::assertStringContainsString("+echo '<untracked>';", $diff->patch);
    }

    public function testMissingBaseCommitFailsClosedInsteadOfGuessingHead(): void
    {
        $root = $this->tempDir();
        $contract = new TaskContract(
            taskId: 'REVIEW-2',
            goal: 'Review without baseline.',
            scope: ['README.md'],
            nonGoals: [],
            validation: ['composer test'],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-08-20T20:00:00+00:00',
            updatedAt: '2026-08-20T20:00:00+00:00',
            path: $root . '/contract.json',
            plannedBy: 'planner',
        );

        $diff = (new HumanReviewDiffCollector($root))->collect($contract);

        self::assertFalse($diff->available);
        self::assertSame([], $diff->changedFiles);
        self::assertStringContainsString('no base commit', strtolower($diff->unavailableReason ?? ''));
    }

    private function gitAvailable(): bool
    {
        $result = $this->process(getcwd() ?: '.', ['git', '--version']);

        return $result['exit'] === 0;
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
        $dir = sys_get_temp_dir() . '/agent-loop-human-review-' . bin2hex(random_bytes(6));
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
