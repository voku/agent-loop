<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Index\AgentMapBuilder;

#[Group('slow')]
final class CachedAgentMapBuilderTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
        putenv('AGENT_LOOP_TEST_MAP_CACHE');
    }

    public function testACacheHitInAnotherRootEqualsARealBuildThere(): void
    {
        $service = "<?php\nnamespace Demo;\nfinal class Service { public function cachedFixtureMarker" . bin2hex(random_bytes(4)) . "(): void {} }\n";
        $first = $this->fixture($service);
        $second = $this->fixture($service);

        CachedAgentMapBuilder::build($first, ['src'], []);
        $hitsBefore = CachedAgentMapBuilder::$hits;
        $cached = CachedAgentMapBuilder::build($second, ['src'], []);
        $real = (new AgentMapBuilder())->build($second, ['src'], []);

        self::assertSame($hitsBefore + 1, CachedAgentMapBuilder::$hits, 'The identical fixture in a new root must be served from the cache.');
        self::assertSame($real->toArray(), $cached->toArray());
        $json = (string) json_encode($cached->toArray(), JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString(str_replace('\\', '/', (string) realpath($second)), $json);
        self::assertStringNotContainsString('@@AGENT_LOOP_TEST_ROOT@@', $json);
    }

    public function testAChangedSourceIsNotServedFromTheCache(): void
    {
        $marker = bin2hex(random_bytes(4));
        $first = $this->fixture("<?php\nnamespace Demo;\nfinal class Service { public function changedMarker{$marker}(): void {} }\n");
        $changed = $this->fixture("<?php\nnamespace Demo;\nfinal class Service { public function changedMarker{$marker}(): void {} public function added(): void {} }\n");

        CachedAgentMapBuilder::build($first, ['src'], []);
        $missesBefore = CachedAgentMapBuilder::$misses;
        $map = CachedAgentMapBuilder::build($changed, ['src'], []);

        self::assertSame($missesBefore + 1, CachedAgentMapBuilder::$misses);
        self::assertSame('added', $map->resolveMethod('Demo\\Service::added')->method->name);
    }

    public function testTheCacheCanBeBypassedForARealBuild(): void
    {
        $root = $this->fixture("<?php\nnamespace Demo;\nfinal class Service { public function bypass" . bin2hex(random_bytes(4)) . "(): void {} }\n");
        putenv('AGENT_LOOP_TEST_MAP_CACHE=0');
        $hits = CachedAgentMapBuilder::$hits;
        $misses = CachedAgentMapBuilder::$misses;

        CachedAgentMapBuilder::build($root, ['src'], []);
        CachedAgentMapBuilder::build($root, ['src'], []);

        self::assertSame($hits, CachedAgentMapBuilder::$hits);
        self::assertSame($misses, CachedAgentMapBuilder::$misses);
    }

    private function fixture(string $serviceSource): string
    {
        $root = sys_get_temp_dir() . '/agent-loop-cached-map-' . bin2hex(random_bytes(6));
        mkdir($root . '/src', 0o775, true);
        file_put_contents($root . '/src/Service.php', $serviceSource);
        $this->roots[] = $root;

        return $root;
    }
}
