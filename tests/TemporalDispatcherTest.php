<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dispatcher;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Temporal\EntityObservation;
use voku\AgentMap\Temporal\GitRevision;
use voku\AgentMap\Temporal\TemporalHistoryStore;

/** @internal */
final class TemporalDispatcherTest extends TestCase
{
    public function testHistoryDiffRoutesWithoutInjectingCurrentMapOptions(): void
    {
        $directory = $this->temporaryDirectory();
        $source = $directory . '/src';
        self::assertTrue(mkdir($source, 0o775, true));
        self::assertNotFalse(file_put_contents(
            $source . '/Foo.php',
            "<?php\nnamespace App;\nfinal class Foo { public function run(): void {} }\n",
        ));

        try {
            $dispatcher = new Dispatcher($directory);
            ob_start();
            $build = $dispatcher->run(['agent-loop', 'map', 'build', '--paths=src']);
            ob_end_clean();
            self::assertSame(0, $build);

            $map = $directory . '/.agent-loop/map/php-symbols.json';
            $before = $directory . '/before.json';
            self::assertFileExists($map);
            self::assertTrue(copy($map, $before));

            ob_start();
            $status = $dispatcher->run([
                'agent-loop',
                'map',
                'history',
                'diff',
                '--before=' . $before,
                '--after=' . $map,
                '--format=json',
            ]);
            $output = ob_get_clean();

            self::assertSame(0, $status);
            self::assertIsString($output);
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('history_diff', $payload['type'] ?? null);
            self::assertSame(0, $payload['event_count'] ?? null);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function testHistoryShowUsesLoopHistoryDatabaseWithoutInjectingMapIndex(): void
    {
        $directory = $this->temporaryDirectory();
        $agentMap = $directory . '/.agent-loop/map';
        self::assertTrue(mkdir($agentMap, 0o775, true));
        $database = $agentMap . '/history.sqlite';
        $entityId = 'method:App\\Foo::run';

        try {
            $store = new TemporalHistoryStore($database);
            $store->record(
                new AgentMapIndex('2.0', $directory, 'test', []),
                new GitRevision(str_repeat('a', 40), '2026-08-11T04:00:00+00:00'),
                [new EntityObservation(
                    'method',
                    $entityId,
                    'src/Foo.php',
                    'public run(): void',
                    2,
                    1,
                    3,
                    1,
                    'region-domain',
                    'Domain',
                )],
            );

            ob_start();
            $status = (new Dispatcher($directory))->run([
                'agent-loop',
                'map',
                'history',
                'show',
                $entityId,
                '--format=json',
            ]);
            $output = ob_get_clean();

            self::assertSame(0, $status);
            self::assertIsString($output);
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('history_entity', $payload['type'] ?? null);
            self::assertSame($entityId, $payload['entity_id'] ?? null);
            self::assertSame('present', $payload['status'] ?? null);
            self::assertSame(1, $payload['observation_count'] ?? null);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/agent-loop-temporal-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o775, true));

        return $directory;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
