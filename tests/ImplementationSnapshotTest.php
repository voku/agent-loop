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

    public function testExplicitScopedVendorPackageCanBeCaptured(): void
    {
        $vendorPkgDir = $this->root . '/vendor/voku/agent-loop/src';
        mkdir($vendorPkgDir, 0o775, true);
        file_put_contents($vendorPkgDir . '/Test.php', "<?php // test\n");

        $snapshot = ImplementationSnapshot::capture($this->root, $this->contract('SNAP-VENDOR-1', ['vendor/voku/agent-loop']));

        self::assertSame(['vendor/voku/agent-loop/src/Test.php'], array_column($snapshot->files, 'path'));
    }

    public function testRootVendorDirectoryRemainsExcludedAsDependencyMetadata(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workflow/dependency metadata');

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-VENDOR-ROOT', ['vendor']));
    }

    public function testCustomLearningHistoryInsideScopedParentDirectoryDoesNotAffectSnapshot(): void
    {
        mkdir($this->root . '/infra/doc/agent-learning/history/run-learning', 0o775, true);
        mkdir($this->root . '/infra/src', 0o775, true);
        file_put_contents($this->root . '/infra/src/Infra.php', "<?php // infra\n");
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'paths' => ['learning_root' => 'infra/doc/agent-learning'],
            ], JSON_THROW_ON_ERROR),
        );

        $contract = $this->contract('SNAP-CUSTOM-LEARNING-1', ['infra']);
        $before = ImplementationSnapshot::capture($this->root, $contract);

        self::assertSame(['infra/src/Infra.php'], array_column($before->files, 'path'));

        // Adding run-learning history must not change the snapshot digest
        file_put_contents(
            $this->root . '/infra/doc/agent-learning/history/run-learning/decision.json',
            "{\"decision\":\"no_durable_learning\"}\n",
        );

        $afterHistoryWrite = ImplementationSnapshot::capture($this->root, $contract);
        self::assertSame($before->digest, $afterHistoryWrite->digest);
        self::assertSame($before->files, $afterHistoryWrite->files);

        // Modifying code inside the scoped parent directory must change the snapshot digest
        file_put_contents($this->root . '/infra/src/Infra.php', "<?php // infra v2\n");
        $afterCodeChange = ImplementationSnapshot::capture($this->root, $contract);
        self::assertNotSame($before->digest, $afterCodeChange->digest);
    }

    public function testCustomLearningHistoryDirectoryRemainsExcludedAsMetadata(): void
    {
        mkdir($this->root . '/infra/doc/agent-learning/history', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'version' => 1,
                'paths' => ['learning_root' => 'infra/doc/agent-learning'],
            ], JSON_THROW_ON_ERROR),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workflow/dependency metadata');

        ImplementationSnapshot::capture($this->root, $this->contract('SNAP-CUSTOM-LEARNING-DIR', ['infra/doc/agent-learning/history']));
    }

    public function testScopedFileDeletedSinceBaseCommitIsRecordedAsExplicitDeletion(): void
    {
        file_put_contents($this->root . '/src/Gone.php', "<?php\nreturn 'gone';\n");
        $contract = $this->contractAt('SNAP-DELETE-1', ['src/A.php', 'src/Gone.php'], $this->commitAll());
        $before = ImplementationSnapshot::capture($this->root, $contract);

        unlink($this->root . '/src/Gone.php');
        $afterDeletion = ImplementationSnapshot::capture($this->root, $contract);

        self::assertSame(['src/A.php'], array_column($afterDeletion->files, 'path'));
        self::assertSame(['src/Gone.php'], $afterDeletion->deleted);
        self::assertSame(['src/Gone.php'], $afterDeletion->toArray()['deleted'] ?? null);
        self::assertNotSame($before->digest, $afterDeletion->digest);

        file_put_contents($this->root . '/src/Gone.php', "<?php\nreturn 'gone';\n");
        self::assertSame($before->digest, ImplementationSnapshot::capture($this->root, $contract)->digest);
    }

    public function testScopedDirectoryDeletedSinceBaseCommitIsRecordedEvenWhenNothingElseRemains(): void
    {
        mkdir($this->root . '/legacy/skill', 0o775, true);
        file_put_contents($this->root . '/legacy/skill/SKILL.md', "# Legacy\n");
        $contract = $this->contractAt('SNAP-DELETE-DIR-1', ['legacy/skill'], $this->commitAll());

        $this->removeDirectory($this->root . '/legacy');
        $snapshot = ImplementationSnapshot::capture($this->root, $contract);

        self::assertSame([], $snapshot->files);
        self::assertSame(['legacy/skill'], $snapshot->deleted);
    }

    public function testMissingScopedPathNeverTrackedAtBaseCommitStaysUnavailable(): void
    {
        $contract = $this->contractAt('SNAP-DELETE-NEVER-1', ['src/Never.php'], $this->commitAll());

        $this->expectException(ImplementationSnapshotUnavailable::class);
        $this->expectExceptionMessage('scoped path does not exist yet: src/Never.php');

        ImplementationSnapshot::capture($this->root, $contract);
    }

    public function testSnapshotWithoutDeletionsKeepsItsPersistedDigestPayload(): void
    {
        $snapshot = ImplementationSnapshot::capture($this->root, $this->contract('SNAP-PAYLOAD-1', ['src/A.php']));

        $expected = 'sha256:' . hash('sha256', \voku\AgentLoop\Run\CanonicalJson::pretty([
            'schema_version' => '1.0',
            'contract_revision' => 1,
            'files' => [['path' => 'src/A.php', 'sha256' => 'sha256:' . hash_file('sha256', $this->root . '/src/A.php')]],
        ]));
        self::assertSame($expected, $snapshot->digest);
        self::assertSame([], $snapshot->deleted);
        self::assertArrayNotHasKey('deleted', $snapshot->toArray());
    }

    /** @param list<string> $scope */
    private function contract(string $taskId, array $scope): \voku\AgentLoop\Workflow\TaskContract
    {
        $store = new TaskContractStore($this->root);
        $store->create($taskId, 'Snapshot fixture.', $scope, [], ['composer test'], 'fixture');

        return $store->approve($taskId, 'fixture');
    }

    /** @param list<string> $scope */
    private function contractAt(string $taskId, array $scope, string $baseCommit): \voku\AgentLoop\Workflow\TaskContract
    {
        $store = new TaskContractStore($this->root);
        $store->create($taskId, 'Snapshot fixture.', $scope, [], ['composer test'], 'fixture', $baseCommit);

        return $store->approve($taskId, 'fixture');
    }

    private function commitAll(): string
    {
        foreach ([
            ['git', 'init', '-q'],
            ['git', 'config', 'user.email', 'snapshot@example.invalid'],
            ['git', 'config', 'user.name', 'Snapshot Fixture'],
            ['git', 'config', 'commit.gpgsign', 'false'],
            ['git', 'add', '-A'],
            ['git', 'commit', '-q', '--no-verify', '-m', 'base'],
        ] as $command) {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
            self::assertIsResource($process);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), implode(' ', $command) . "\n" . $output);
        }

        $head = \voku\AgentLoop\GitWorkTree::headCommit($this->root);
        self::assertNotNull($head);

        return $head;
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
