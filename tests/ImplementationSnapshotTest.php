<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\ImplementationSnapshotUnavailable;
use voku\AgentLoop\Workflow\TaskContractStore;

final class ImplementationSnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-implementation-snapshot-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/learning/proposals/approved', 0o775, true);
        file_put_contents($this->root . '/src/A.php', "<?php\nreturn 'A';\n");
        file_put_contents($this->root . '/src/B.php', "<?php\nreturn 'B';\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSnapshotChangesOnlyWhenApprovedImplementationScopeChanges(): void
    {
        $contract = $this->contract('SNAP-1', ['src/A.php']);
        $a = ImplementationSnapshot::capture($this->root, $contract);

        file_put_contents($this->root . '/.agent-loop/learning/runtime-note.json', '{}');
        $metadataOnly = ImplementationSnapshot::capture($this->root, $contract);
        self::assertSame($a->digest, $metadataOnly->digest);

        file_put_contents($this->root . '/src/A.php', "<?php\nreturn 'B';\n");
        $b = ImplementationSnapshot::capture($this->root, $contract);
        self::assertNotSame($a->digest, $b->digest);
        self::assertSame('1.0', $a->toArray()['schema_version']);
        self::assertSame(1, $a->contractRevision);
    }

    public function testExplicitDurableLearningArtifactCanBeImplementationScope(): void
    {
        $path = '.agent-loop/learning/proposals/approved/proposal.json';
        file_put_contents($this->root . '/' . $path, "{\"validation\":[\"old\"]}\n");
        $contract = $this->contract('SNAP-LEARNING-1', [$path]);

        $before = ImplementationSnapshot::capture($this->root, $contract);
        file_put_contents($this->root . '/' . $path, "{\"validation\":[\"new\"]}\n");
        $after = ImplementationSnapshot::capture($this->root, $contract);

        self::assertSame([$path], array_column($before->files, 'path'));
        self::assertNotSame($before->digest, $after->digest);
    }

    public function testMissingExplicitDurableLearningArtifactIsUnavailableRatherThanWorkflowMetadata(): void
    {
        $path = '.agent-loop/learning/constraints/active/constraint.example.json';

        $this->expectException(ImplementationSnapshotUnavailable::class);
        $this->expectExceptionMessage('scoped path does not exist yet: ' . $path);

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-LEARNING-NEW-1', [$path]));
    }

    public function testExplicitGeneratedRunStateRemainsExcluded(): void
    {
        $path = '.agent-loop/runs/SNAP-RUN/manifest.json';
        mkdir(dirname($this->root . '/' . $path), 0o775, true);
        file_put_contents($this->root . '/' . $path, "{}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workflow/dependency metadata');

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-RUN-1', [$path]));
    }

    public function testMissingExplicitGeneratedRunStateRemainsExcluded(): void
    {
        $path = '.agent-loop/runs/SNAP-RUN-MISSING/manifest.json';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workflow/dependency metadata');

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-RUN-MISSING-1', [$path]));
    }

    public function testExplicitLearningHistoryRemainsExcluded(): void
    {
        $path = '.agent-loop/learning/history/run.json';
        mkdir(dirname($this->root . '/' . $path), 0o775, true);
        file_put_contents($this->root . '/' . $path, "{}\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workflow/dependency metadata');

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-HISTORY-1', [$path]));
    }

    public function testExternalWorkflowStateRootDoesNotBecomeImplementationScope(): void
    {
        $externalStateRoot = sys_get_temp_dir() . '/agent-loop-external-state-' . bin2hex(random_bytes(6));
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'paths' => ['state_root' => $externalStateRoot],
            ], JSON_THROW_ON_ERROR),
        );

        $snapshot = ImplementationSnapshot::capture($this->root, $this->contract('SNAP-EXT-1', ['src/A.php']));

        self::assertSame(['src/A.php'], array_column($snapshot->files, 'path'));
    }

    public function testSnapshotIsStableAcrossScopeOrderingAndNeedsNoGitCommit(): void
    {
        $left = ImplementationSnapshot::capture($this->root, $this->contract('SNAP-2', ['src/B.php', 'src/A.php']));
        $right = ImplementationSnapshot::capture($this->root, $this->contract('SNAP-3', ['src/A.php', 'src/B.php']));

        self::assertSame($left->files, $right->files);
        self::assertSame($left->digest, $right->digest);
    }

    public function testMissingScopedFileIsNeverSilentlySkipped(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('src/Missing.php');

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-4', ['src/Missing.php']));
    }

    public function testSymlinkInsideScopedDirectoryIsNeverSilentlySkipped(): void
    {
        self::assertTrue(symlink('A.php', $this->root . '/src/Linked.php'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('src/Linked.php');

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-5', ['src']));
    }

    /** @param list<string> $scope */
    private function contract(string $taskId, array $scope): \voku\AgentLoop\Workflow\TaskContract
    {
        $store = new TaskContractStore($this->root);
        $store->create($taskId, 'Snapshot fixture.', $scope, [], ['composer test'], 'fixture');

        return $store->approve($taskId, 'fixture');
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
