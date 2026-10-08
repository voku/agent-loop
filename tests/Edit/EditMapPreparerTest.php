<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Edit;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\EditMapPreparer;
use voku\AgentLoop\Edit\EditRequest;
use voku\AgentMap\Index\IndexReader;

/**
 * Pins how the edit pipeline obtains its map: missing, current, stale, added, deleted and moved
 * files, a runtime root that differs from the indexed root, and the read-only `--no-rebuild-map`
 * mode. These are the semantics that must survive moving map maintenance behind agent-map's owner API.
 */
#[Group('slow')]
final class EditMapPreparerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-edit-map-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        $this->write('src/Alpha.php', 'Alpha', 'run');
        $this->write('src/Beta.php', 'Beta', 'go');
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

    public function testMissingIndexIsBuiltAndPublishedWhenRebuildingIsAllowed(): void
    {
        $map = (new EditMapPreparer())->prepare($this->request());

        self::assertFileExists($this->index());
        self::assertSame(['src/Alpha.php', 'src/Beta.php'], $this->paths($map));
        self::assertSame('src/Alpha.php', $map->resolveMethod('Demo\Alpha::run')->file->path);
    }

    public function testMissingIndexIsRefusedWithoutWritingWhenRebuildingIsForbidden(): void
    {
        try {
            (new EditMapPreparer())->prepare($this->request(allowRebuild: false));
            self::fail('A missing map must not be built when rebuilding is forbidden.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('automatic rebuilding is disabled', $exception->getMessage());
        }

        self::assertFileDoesNotExist($this->index());
    }

    public function testCurrentIndexIsReusedWithoutRewriting(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        $before = $this->snapshot($this->index());

        $map = (new EditMapPreparer())->prepare($this->request());

        self::assertSame($before, $this->snapshot($this->index()));
        self::assertSame(['src/Alpha.php', 'src/Beta.php'], $this->paths($map));
    }

    public function testStaleIndexIsRebuiltAndPublishedWhenAllowed(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        $this->write('src/Alpha.php', 'Alpha', 'runRenamed');

        $map = (new EditMapPreparer())->prepare($this->request(target: 'Demo\Alpha::runRenamed'));

        self::assertSame([], $map->staleEntries());
        self::assertSame([], (new IndexReader())->read($this->index())->staleEntries(), 'the rebuilt map is published');
    }

    public function testStaleIndexIsRefusedAndLeftUntouchedWhenRebuildingIsForbidden(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        $this->write('src/Alpha.php', 'Alpha', 'runRenamed');
        $before = $this->snapshot($this->index());

        try {
            (new EditMapPreparer())->prepare($this->request(allowRebuild: false));
            self::fail('A stale map must not be rebuilt when rebuilding is forbidden.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('stale and automatic rebuilding is disabled', $exception->getMessage());
            self::assertStringContainsString('src/Alpha.php', $exception->getMessage());
        }

        self::assertSame($before, $this->snapshot($this->index()));
    }

    public function testDeletedFileIsDroppedFromTheRebuiltMap(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        unlink($this->root . '/src/Beta.php');

        $map = (new EditMapPreparer())->prepare($this->request());

        self::assertSame(['src/Alpha.php'], $this->paths($map));
    }

    public function testMovedFileIsReindexedUnderItsNewPath(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        mkdir($this->root . '/src/Moved', 0o775, true);
        rename($this->root . '/src/Beta.php', $this->root . '/src/Moved/Beta.php');

        $map = (new EditMapPreparer())->prepare($this->request());

        self::assertSame(['src/Alpha.php', 'src/Moved/Beta.php'], $this->paths($map));
    }

    /**
     * Characterization: a file that was added next to an otherwise current map is invisible to the
     * stale check, so the map is reused as is. Only a forced rebuild picks it up.
     */
    public function testAddedFileIsNotPickedUpUnlessTheMapIsRebuilt(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        $this->write('src/Gamma.php', 'Gamma', 'fire');

        self::assertSame(['src/Alpha.php', 'src/Beta.php'], $this->paths((new EditMapPreparer())->prepare($this->request())));
        self::assertSame(
            ['src/Alpha.php', 'src/Beta.php', 'src/Gamma.php'],
            $this->paths((new EditMapPreparer())->prepare($this->request(forceRebuild: true))),
        );
    }

    public function testForcedRebuildRewritesACurrentMapButIsRefusedWhenRebuildingIsForbidden(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        $before = $this->snapshot($this->index());

        try {
            (new EditMapPreparer())->prepare($this->request(forceRebuild: true, allowRebuild: false));
            self::fail('A forced rebuild must be refused when rebuilding is forbidden.');
        } catch (RuntimeException) {
        }
        self::assertSame($before, $this->snapshot($this->index()));

        $map = (new EditMapPreparer())->prepare($this->request(forceRebuild: true));
        self::assertSame(['src/Alpha.php', 'src/Beta.php'], $this->paths($map));
    }

    public function testRuntimeRootOverridesTheIndexedRootWithoutTouchingThePublishedIndex(): void
    {
        (new EditMapPreparer())->prepare($this->request());
        $before = $this->snapshot($this->index());
        $runtime = $this->root . '/runtime-view';
        mkdir($runtime . '/src', 0o775, true);
        copy($this->root . '/src/Alpha.php', $runtime . '/src/Alpha.php');
        copy($this->root . '/src/Beta.php', $runtime . '/src/Beta.php');

        $map = (new EditMapPreparer())->prepare($this->request(mapRoot: $runtime));

        self::assertSame($runtime, $map->root);
        self::assertSame($before, $this->snapshot($this->index()));
    }

    public function testTargetThatDoesNotResolveFailsAtTheBoundary(): void
    {
        $this->expectException(RuntimeException::class);

        (new EditMapPreparer())->prepare($this->request(target: 'Demo\Alpha::missing'));
    }

    private function request(
        bool $forceRebuild = false,
        bool $allowRebuild = true,
        ?string $mapRoot = null,
        string $target = 'Demo\Alpha::run',
    ): EditRequest {
        return new EditRequest(
            taskId: 'MAP-PREP',
            target: $target,
            instruction: 'prepare the map',
            projectRoot: $this->root,
            recallRoot: $this->root . '/.agent-loop/recall',
            mapIndex: $this->index(),
            mapRoot: $mapRoot ?? $this->root,
            outputDirectory: $this->root . '/.agent-loop/edit/MAP-PREP',
            mapPaths: ['src'],
            forceMapRebuild: $forceRebuild,
            allowMapRebuild: $allowRebuild,
        );
    }

    private function index(): string
    {
        return $this->root . '/.agent-loop/map/php-symbols.json';
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

    /** @return list<string> */
    private function paths(\voku\AgentMap\Index\AgentMapIndex $map): array
    {
        $paths = array_map(static fn ($file): string => $file->path, $map->files);
        sort($paths);

        return $paths;
    }

    private function snapshot(string $file): string
    {
        return hash_file('sha256', $file) . ':' . filemtime($file);
    }
}
