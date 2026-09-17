<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\WorkflowRunPreparer;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;

/**
 * Automatic discovery repair patches the Contract scope into the shared index; it never replaces it.
 *
 * A single Contract file outside the indexed paths used to trigger a scope-sized
 * structural build written over a full semantic index, so every later map query,
 * planner and Recall evidence saw only the handful of files that one Contract touched.
 */
final class WorkflowRunPreparerMapPreservationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-map-preservation-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o700, true) || !mkdir($this->root . '/scripts', 0o700, true)) {
            throw new RuntimeException('Unable to create fixture root.');
        }
        file_put_contents($this->root . '/src/Indexed.php', "<?php\n\nfinal class Indexed {}\n");
        file_put_contents($this->root . '/scripts/Scoped.php', "<?php\n\nfinal class Scoped {}\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAStructuralIndexKeepsUnrelatedFilesWhenTheScopeIsPatchedIn(): void
    {
        $this->buildStructuralIndexOfSrc();

        (new WorkflowRunPreparer($this->root))->reconcileDiscovery($this->contract());

        $map = (new IndexReader())->read($this->governedIndex());
        self::assertNotNull($map->file('src/Indexed.php'));
        self::assertNotNull($map->file('scripts/Scoped.php'));
        self::assertSame('simple-php-code-parser+structural-only', $map->backend);
    }

    public function testReconcileDiscoveryAutomaticallyRepairsStaleEntries(): void
    {
        $this->buildStructuralIndexOfSrc();

        // Mutate src/Indexed.php so the index becomes stale
        file_put_contents($this->root . '/src/Indexed.php', "<?php\n\nfinal class Indexed { public function added(): void {} }\n");

        $readiness = (new WorkflowRunPreparer($this->root))->reconcileDiscovery($this->contract());

        self::assertSame('ready', $readiness->mapState);
        $map = (new IndexReader())->read($this->governedIndex());
        $file = $map->file('src/Indexed.php');
        self::assertNotNull($file);
        self::assertNotEmpty($file->symbols);
        self::assertSame('Indexed', $file->symbols[0]->name);
        self::assertNotNull($map->file('scripts/Scoped.php'));
    }

    public function testAnIndexOfABackendNoAutomaticBuilderProducesIsRefusedAndLeftByteIdentical(): void
    {
        $this->buildStructuralIndexOfSrc();
        file_put_contents(
            $this->governedIndex(),
            str_replace(
                'simple-php-code-parser+structural-only',
                'simple-php-code-parser+unknown-analyzer',
                (string) file_get_contents($this->governedIndex()),
            ),
        );
        $before = (string) file_get_contents($this->governedIndex());

        try {
            (new WorkflowRunPreparer($this->root))->reconcileDiscovery($this->contract());
            self::fail('discovery repair replaced or merged an index of another backend.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($this->governedIndex(), $exception->getMessage());
            self::assertStringContainsString('left untouched', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($this->governedIndex()));
    }

    private function buildStructuralIndexOfSrc(): void
    {
        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        (new IndexWriter())->write(
            (new AgentMapBuilder(semanticAnalyzer: new StructuralOnlySemanticAnalyzer(), artifacts: $artifacts))
                ->build($this->root, ['src'], []),
            $this->governedIndex(),
        );
    }

    private function contract(): TaskContract
    {
        return new TaskContract(
            taskId: 'MAP-PRESERVE-1',
            goal: 'Change one file outside the indexed paths.',
            scope: ['scripts/Scoped.php'],
            nonGoals: [],
            validation: ['composer ci'],
            status: TaskContract::APPROVED,
            revision: 1,
            createdAt: '2026-09-15T00:00:00+00:00',
            updatedAt: '2026-09-15T00:00:00+00:00',
            path: $this->root . '/contract.json',
            plannedBy: 'planner',
            approvedBy: 'approver',
            approvedAt: '2026-09-15T00:00:00+00:00',
        );
    }

    private function governedIndex(): string
    {
        return $this->root . '/.agent-loop/map/php-symbols.json';
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            if (is_link($full)) {
                unlink($full);
                continue;
            }
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
