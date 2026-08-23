<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\Refactor\MethodRemovalPlanApplier;
use voku\AgentLoop\Edit\Refactor\MethodRemovalVerifyCommand;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Removal\MethodRemovalPlanner;

final class MethodRemovalVerifyCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-method-removal-verify-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/bundle', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    private function obsolete(): void
    {
    }

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
        $plan = (new MethodRemovalPlanner())->plan($beforeMap, 'Demo\\Service::obsolete')->toArray();
        $planJson = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $planPath = $this->root . '/bundle/plan.json';
        file_put_contents($planPath, $planJson);

        (new MethodRemovalPlanApplier())->apply($plan, $beforeMap, $this->root);
        $currentMap = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($currentMap, $mapPath);

        file_put_contents($this->root . '/bundle/execution.json', json_encode([
            'schema_version' => '1.0',
            'status' => 'runner_succeeded',
            'task_id' => 'REMOVE-1',
            'plan' => [
                'path' => $planPath,
                'sha256' => 'sha256:' . hash('sha256', $planJson),
                'type' => 'method_removal_plan',
                'contract_version' => '1.0',
                'target_id' => $plan['target_id'],
            ],
            'runner' => [
                'name' => 'method-removal-plan',
                'exit_code' => 0,
                'dry_run' => false,
            ],
            'changed_files' => ['src/Service.php'],
            'changed_files_source' => 'git_status_diff',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        ob_start();
        $exit = (new MethodRemovalVerifyCommand($this->root))->run([
            '--bundle=bundle',
            '--map-index=map.json',
            '--map-root=.',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        $result = json_decode(
            (string) file_get_contents($this->root . '/bundle/' . MethodRemovalVerifyCommand::FILE_NAME),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertSame('passed', $result['status']);
        self::assertSame('method_removal_plan_verification', $result['kind']);
        self::assertSame('passed', $result['checks']['target_absent']);
    }
}
