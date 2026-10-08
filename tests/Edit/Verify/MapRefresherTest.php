<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Edit\Verify;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\Verify\MapRefresher;
use voku\AgentLoop\Edit\Verify\ObjectiveGateRunner;
use voku\AgentLoop\Edit\Verify\VerificationBundle;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;

/**
 * Pins the post-edit map refresh and the two gates that read it. Verification is an observation:
 * the shared index must stay byte-identical, and a refresh that cannot run must read `not_run`.
 */
#[Group('slow')]
final class MapRefresherTest extends TestCase
{
    private const TARGET = 'Demo\Alpha::run';

    private string $root;
    private string $bundle;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-map-refresher-' . bin2hex(random_bytes(6));
        $this->bundle = $this->root . '/.agent-loop/edit/REFRESH';
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->bundle, 0o775, true);
        $this->write('src/Alpha.php', 'Alpha', 'run');
        $this->write('src/Beta.php', 'Beta', 'go');

        $index = (new AgentMapBuilder())->build($this->root, ['src'], []);
        (new IndexWriter())->write($index, $this->sharedIndex());
        file_put_contents($this->bundle . '/request.json', json_encode(['map_index' => $this->sharedIndex()], JSON_THROW_ON_ERROR));
    }

    /**
     * `MapRefresher` copies only `php-symbols.json`, but a real index declares a `php-relations.json`
     * companion that must travel with it. Behaviour tests seed the complete set so they pin the
     * refresh/gate semantics; the companion defect has its own test.
     */
    private function seedCompleteBundleIndex(): void
    {
        copy($this->sharedIndex(), $this->bundleIndex());
        copy(dirname($this->sharedIndex()) . '/php-relations.json', dirname($this->bundleIndex()) . '/php-relations.json');
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** Characterization of a latent defect: copying the symbols file alone cannot be read back. */
    public function testIndexWithARelationsCompanionCannotBeRefreshedByASymbolsOnlyCopy(): void
    {
        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertFalse($result['available']);
        self::assertStringContainsString('php-relations.json', $result['detail']);
    }

    public function testBundleWithoutAMapIndexReportsUnavailable(): void
    {
        unlink($this->bundle . '/request.json');

        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertFalse($result['available']);
        self::assertNull($result['index']);
    }

    public function testCurrentMapIsCopiedIntoTheBundleAndSharedIndexIsUntouched(): void
    {
        $this->seedCompleteBundleIndex();
        $before = $this->sharedSnapshot();

        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertTrue($result['available'], $result['detail']);
        self::assertSame([], $result['stale']);
        self::assertSame($this->bundleIndex(), $result['index']);
        self::assertFileExists($this->bundleIndex());
        self::assertSame($before, $this->sharedSnapshot());
    }

    public function testChangedFileIsRefreshedInTheBundleCopyOnly(): void
    {
        $this->seedCompleteBundleIndex();
        $before = $this->sharedSnapshot();
        $this->write('src/Alpha.php', 'Alpha', 'runRenamed');

        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertTrue($result['available']);
        self::assertSame([], $result['stale']);
        self::assertSame($before, $this->sharedSnapshot(), 'verification must not rewrite the shared index');
        self::assertSame('src/Alpha.php', $this->readIndex($this->bundleIndex())->resolveMethod('Demo\Alpha::runRenamed')->file->path);
    }

    /**
     * Characterization of the CURRENT behaviour: a deleted file stays stale, so `post_edit_map_fresh`
     * fails. After the owner-API migration the deleted entry is pruned and this expectation flips;
     * `target_resolvable` is then the gate that still protects a deleted target.
     */
    public function testDeletedFileIsReportedStale(): void
    {
        $this->seedCompleteBundleIndex();
        unlink($this->root . '/src/Beta.php');

        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertTrue($result['available']);
        self::assertSame(['src/Beta.php'], $result['stale']);
    }

    public function testAddedFileIsNotIndexedByTheRefresh(): void
    {
        $this->seedCompleteBundleIndex();
        $this->write('src/Gamma.php', 'Gamma', 'fire');

        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertSame([], $result['stale']);
        self::assertSame(['src/Alpha.php', 'src/Beta.php'], $this->indexedPaths($this->bundleIndex()));
    }

    public function testMovedFileLeavesItsOldPathStaleAndIsNotIndexedUnderTheNewOne(): void
    {
        $this->seedCompleteBundleIndex();
        mkdir($this->root . '/src/Moved', 0o775, true);
        rename($this->root . '/src/Beta.php', $this->root . '/src/Moved/Beta.php');

        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertSame(['src/Beta.php'], $result['stale']);
        self::assertSame(['src/Alpha.php', 'src/Beta.php'], $this->indexedPaths($this->bundleIndex()));
    }

    public function testUnreadableIndexReportsUnavailableInsteadOfPassing(): void
    {
        file_put_contents($this->sharedIndex(), '{not json');

        $result = (new MapRefresher())->refresh($this->loadBundle(), $this->root);

        self::assertFalse($result['available']);
        self::assertStringContainsString('refresh failed', $result['detail']);
    }

    public function testGatesPassForACurrentMapWithAResolvableTarget(): void
    {
        self::assertSame(['passed', 'passed'], $this->gateStatuses());
    }

    public function testGatesPassWhenAChangedFileStillResolvesTheTarget(): void
    {
        file_put_contents($this->root . '/src/Alpha.php', <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class Alpha
        {
            public function run(): void
            {
            }

            public function extra(): void
            {
            }
        }
        PHP);

        self::assertSame(['passed', 'passed'], $this->gateStatuses());
    }

    /**
     * Characterization of the CURRENT behaviour, and the reason pruning deleted files is safe: today
     * the stale entry of a deleted target file is never refreshed, so `target_resolvable` still
     * reports `passed` and only `post_edit_map_fresh` fails. Once deleted entries are pruned the
     * target gate fails by itself, so the overall objective gate no longer depends on freshness.
     */
    public function testDeletingTheTargetFileFailsFreshnessAndTodayFalselyPassesTargetResolvable(): void
    {
        unlink($this->root . '/src/Alpha.php');

        self::assertSame(['failed', 'passed'], $this->gateStatuses());
    }

    public function testRenamingTheTargetMethodFailsTargetResolvable(): void
    {
        $this->write('src/Alpha.php', 'Alpha', 'runRenamed');

        self::assertSame(['passed', 'failed'], $this->gateStatuses());
    }

    public function testDeletingAnUnrelatedFileFailsFreshnessTodayAndMustKeepTheTargetGatePassing(): void
    {
        unlink($this->root . '/src/Beta.php');

        $statuses = $this->gateStatuses();

        self::assertSame('passed', $statuses[1], 'an unrelated deletion must not affect target resolvability');
        self::assertSame('failed', $statuses[0], 'current behaviour: deleted indexed files fail post_edit_map_fresh');
    }

    public function testGatesReadNotRunWhenNoMapWasProduced(): void
    {
        unlink($this->bundle . '/request.json');

        self::assertSame(['not_run', 'not_run'], $this->gateStatuses());
    }

    /** @return array{0: string, 1: string} [post_edit_map_fresh, target_resolvable] */
    private function gateStatuses(bool $seed = true): array
    {
        if ($seed && is_file($this->bundle . '/request.json')) {
            $this->seedCompleteBundleIndex();
        }
        $this->writeVerificationArtifacts();

        $results = (new ObjectiveGateRunner())->run(VerificationBundle::load($this->bundle), $this->root, false);
        $byKind = [];
        foreach ($results as $gate) {
            $byKind[$gate['kind']] = $gate['status'];
        }

        return [$byKind['post_edit_map_fresh'], $byKind['target_resolvable']];
    }

    private function writeVerificationArtifacts(): void
    {
        $recall = $this->root . '/.agent-loop/recall/REFRESH';
        mkdir($recall, 0o775, true);
        $plan = json_encode([
            'task_id' => 'REFRESH',
            'target' => self::TARGET,
            'objective_gates' => [
                ['id' => 'map_fresh', 'kind' => 'post_edit_map_fresh', 'required' => true],
                ['id' => 'target', 'kind' => 'target_resolvable', 'required' => true],
            ],
        ], JSON_THROW_ON_ERROR);
        file_put_contents($recall . '/verification-plan.json', $plan);
        $sha = 'sha256:' . hash('sha256', $plan);
        file_put_contents($recall . '/verification-key.json', json_encode(['plan_sha256' => $sha, 'target' => self::TARGET, 'probes' => []], JSON_THROW_ON_ERROR));
        file_put_contents($this->bundle . '/agent-result.json', json_encode(['task_id' => 'REFRESH', 'target' => self::TARGET, 'verification_plan_sha256' => $sha], JSON_THROW_ON_ERROR));
        file_put_contents($this->bundle . '/execution.json', json_encode(['task_id' => 'REFRESH', 'artifacts' => ['recall' => $recall]], JSON_THROW_ON_ERROR));
    }

    private function loadBundle(): VerificationBundle
    {
        $this->writeVerificationArtifacts();

        return VerificationBundle::load($this->bundle);
    }

    private function sharedIndex(): string
    {
        return $this->root . '/.agent-loop/map/php-symbols.json';
    }

    private function bundleIndex(): string
    {
        return (string) realpath($this->bundle) . '/' . MapRefresher::FILE_NAME;
    }

    private function sharedSnapshot(): string
    {
        return hash_file('sha256', $this->sharedIndex()) . ':' . filemtime($this->sharedIndex());
    }

    private function readIndex(string $file): \voku\AgentMap\Index\AgentMapIndex
    {
        return (new \voku\AgentMap\Index\IndexReader())->read($file);
    }

    /** @return list<string> */
    private function indexedPaths(string $file): array
    {
        $paths = array_map(static fn ($entry): string => $entry->path, $this->readIndex($file)->files);
        sort($paths);

        return $paths;
    }

    private function write(string $path, string $class, string $method): void
    {
        file_put_contents($this->root . '/' . $path, <<<PHP
        <?php

        declare(strict_types=1);

        namespace Demo;

        final class {$class}
        {
            public function {$method}(): void
            {
            }
        }
        PHP);
    }
}
