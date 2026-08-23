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

final class PropertyRemovalVerifyCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-property-removal-verify-' . bin2hex(random_bytes(6));
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

    public function testVerificationRequiresTargetAbsentFromCurrentMap(): void
    {
        $beforeMap = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $plan = $this->plan($beforeMap);
        $planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $planPath = $this->root . '/bundle/plan.json';
        file_put_contents($planPath, $planJson);

        (new PropertyRemovalPlanApplier())->apply($plan, $beforeMap, $this->root);
        $currentMap = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($currentMap, $mapPath);

        file_put_contents($this->root . '/bundle/execution.json', json_encode([
            'schema_version' => '1.0',
            'status' => 'runner_succeeded',
            'task_id' => 'REMOVE-PROPERTY-1',
            'plan' => [
                'path' => $planPath,
                'sha256' => 'sha256:' . hash('sha256', $planJson),
                'type' => 'property_removal_plan',
                'contract_version' => '1.0',
                'target_id' => $plan['target_id'],
            ],
            'runner' => [
                'name' => 'property-removal-plan',
                'exit_code' => 0,
                'dry_run' => false,
            ],
            'changed_files' => ['src/Service.php'],
            'changed_files_source' => 'git_status_diff',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        ob_start();
        $exit = (new PropertyRemovalVerifyCommand($this->root))->run([
            '--bundle=bundle',
            '--map-index=map.json',
            '--map-root=.',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        $result = json_decode(
            (string) file_get_contents($this->root . '/bundle/' . PropertyRemovalVerifyCommand::FILE_NAME),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('passed', $result['status']);
        self::assertSame('property_removal_plan_verification', $result['kind']);
        self::assertSame('passed', $result['checks']['target_absent']);
    }

    /** @return array<string, mixed> */
    private function plan(AgentMapIndex $map): array
    {
        self::assertNotNull($map->fingerprint);
        $path = 'src/Service.php';
        $source = (string) file_get_contents($this->root . '/' . $path);
        $expected = "    private string \$obsolete = 'unused';\n";
        $start = strpos($source, $expected);
        self::assertIsInt($start);

        return [
            'type' => 'property_removal_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => 'property:Demo\\Service::$obsolete',
            'provenance' => [
                'map_digest' => $map->mapDigest(),
                'backend' => $map->backend,
                'analysis_fingerprint' => $map->fingerprint->toArray(),
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
                'symbol_id' => 'property:Demo\\Service::$obsolete',
                'resolution' => 'phpstan_resolved',
            ]],
            'moves' => [],
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => ['Runtime metadata outside the Map remains unobservable.'],
        ];
    }
}
