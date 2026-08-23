<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\Refactor\PropertyRemovalPlanApplier;
use voku\AgentLoop\Edit\Refactor\PropertyRemovalVerifyCommand;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;

final class PropertyRemovalTargetAbsentVerifyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-property-target-absent-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/bundle', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    private string $obsolete = 'unused';

    public function run(): void
    {
    }
}
PHP);
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

    public function testCurrentHashesDoNotHidePersistingPropertyRelation(): void
    {
        $beforeMap = (new AgentMapBuilder())->build($this->root, ['src'], []);
        self::assertNotNull($beforeMap->fingerprint);

        $path = 'src/Service.php';
        $source = (string) file_get_contents($this->root . '/' . $path);
        $expected = "    private string \$obsolete = 'unused';\n";
        $start = strpos($source, $expected);
        self::assertIsInt($start);
        $targetId = 'property:Demo\\Service::$obsolete';

        $plan = [
            'type' => 'property_removal_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => $targetId,
            'provenance' => [
                'map_digest' => $beforeMap->mapDigest(),
                'backend' => $beforeMap->backend,
                'analysis_fingerprint' => $beforeMap->fingerprint->toArray(),
            ],
            'edits' => [[
                'path' => $path,
                'source_sha256' => 'sha256:' . hash('sha256', $source),
                'start_file_pos' => $start,
                'end_file_pos' => $start + strlen($expected) - 1,
                'line_start' => 9,
                'line_end' => 9,
                'expected' => $expected,
                'replacement' => '',
                'role' => 'property_declaration_removal',
                'symbol_id' => $targetId,
                'resolution' => 'phpstan_resolved',
            ]],
            'moves' => [],
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => ['Runtime metadata outside the Map remains unobservable.'],
        ];

        $planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $planPath = $this->root . '/bundle/plan.json';
        file_put_contents($planPath, $planJson);
        (new PropertyRemovalPlanApplier())->apply($plan, $beforeMap, $this->root);

        $currentMap = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $hybridMap = new AgentMapIndex(
            $currentMap->schemaVersion,
            $currentMap->root,
            $currentMap->backend,
            $currentMap->files,
            $beforeMap->relations,
            $currentMap->diagnostics,
            $currentMap->fingerprint,
        );
        self::assertSame([], $hybridMap->staleEntries());
        self::assertTrue($this->declaresProperty($hybridMap, $targetId));
        (new IndexWriter())->write($hybridMap, $this->root . '/map.json');

        file_put_contents($this->root . '/bundle/execution.json', json_encode([
            'schema_version' => '1.0',
            'status' => 'runner_succeeded',
            'task_id' => 'REMOVE-PROPERTY-ABSENT',
            'plan' => [
                'path' => $planPath,
                'sha256' => 'sha256:' . hash('sha256', $planJson),
                'type' => 'property_removal_plan',
                'contract_version' => '1.0',
                'target_id' => $targetId,
            ],
            'runner' => [
                'name' => 'property-removal-plan',
                'exit_code' => 0,
                'dry_run' => false,
            ],
            'changed_files' => [$path],
            'changed_files_source' => 'git_status_diff',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        ob_start();
        $exit = (new PropertyRemovalVerifyCommand($this->root))->run([
            '--bundle=bundle',
            '--map-index=map.json',
            '--map-root=.',
        ]);
        ob_end_clean();

        self::assertSame(2, $exit);
        self::assertFileDoesNotExist($this->root . '/bundle/' . PropertyRemovalVerifyCommand::FILE_NAME);
    }

    private function declaresProperty(AgentMapIndex $map, string $targetId): bool
    {
        foreach ($map->relations as $relation) {
            if ($relation->kind === 'declares_property' && in_array($targetId, $relation->targetIds, true)) {
                return true;
            }
        }

        return false;
    }
}
