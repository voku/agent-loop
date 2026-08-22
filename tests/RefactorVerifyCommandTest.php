<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\Refactor\RefactorVerifyCommand;
use voku\AgentLoop\Edit\Refactor\RenamePlanApplier;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;

final class RefactorVerifyCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-refactor-verify-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop/edit/RENAME-1', 0o775, true);
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
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

    public function testVerifiesAppliedClassEditAndMoveAgainstCurrentMap(): void
    {
        $bundle = $this->prepareAppliedClassRename();

        $exit = (new RefactorVerifyCommand($this->root))->run([
            '--bundle=.agent-loop/edit/RENAME-1',
            '--map-index=.agent-loop/map/php-symbols.json',
            '--map-root=.',
        ]);

        self::assertSame(0, $exit);
        $result = $this->json($bundle . '/verification-result.json');
        self::assertSame('passed', $result['status'] ?? null);
        self::assertSame('rename_plan_verification', $result['kind'] ?? null);
        self::assertSame('class_rename_plan', $result['plan']['type'] ?? null);
        self::assertSame(['src/RenamedService.php', 'src/Service.php'], $result['changed_files'] ?? null);
    }

    public function testRejectsSourceChangedAfterApply(): void
    {
        $bundle = $this->prepareAppliedClassRename();
        file_put_contents($this->root . '/src/RenamedService.php', "\n// changed after apply\n", FILE_APPEND);

        $exit = (new RefactorVerifyCommand($this->root))->run([
            '--bundle=.agent-loop/edit/RENAME-1',
            '--map-index=.agent-loop/map/php-symbols.json',
            '--map-root=.',
        ]);

        self::assertSame(2, $exit);
        self::assertFileDoesNotExist($bundle . '/verification-result.json');
    }

    private function prepareAppliedClassRename(): string
    {
        $beforeMap = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $target = 'class:Demo\\Service';
        $source = (string) file_get_contents($this->root . '/src/Service.php');
        $start = strpos($source, 'Service');
        self::assertIsInt($start);
        $sourceHash = $this->sourceHash($beforeMap, 'src/Service.php');
        $plan = [
            'type' => 'class_rename_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => $target,
            'provenance' => [
                'map_digest' => $beforeMap->mapDigest(),
                'backend' => $beforeMap->backend,
                'analysis_fingerprint' => $beforeMap->fingerprint?->toArray(),
            ],
            'edits' => [[
                'path' => 'src/Service.php',
                'source_sha256' => $sourceHash,
                'start_file_pos' => $start,
                'end_file_pos' => $start + strlen('Service') - 1,
                'line_start' => 7,
                'line_end' => 7,
                'expected' => 'Service',
                'replacement' => 'RenamedService',
                'role' => 'class_declaration',
                'symbol_id' => $target,
                'resolution' => 'parser_resolved',
            ]],
            'moves' => [[
                'from_path' => 'src/Service.php',
                'to_path' => 'src/RenamedService.php',
                'source_sha256' => $sourceHash,
                'reason' => 'same-directory class file move',
            ]],
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => [],
        ];

        (new RenamePlanApplier())->apply($plan, $beforeMap, $this->root);
        $afterMap = (new AgentMapBuilder())->build($this->root, ['src'], []);
        (new IndexWriter())->write($afterMap, $this->root . '/.agent-loop/map/php-symbols.json');

        $bundle = $this->root . '/.agent-loop/edit/RENAME-1';
        $planPath = $bundle . '/plan.json';
        $planRaw = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($planPath, $planRaw);
        file_put_contents($bundle . '/execution.json', json_encode([
            'schema_version' => '1.0',
            'status' => 'runner_succeeded',
            'task_id' => 'RENAME-1',
            'plan' => [
                'path' => $planPath,
                'sha256' => 'sha256:' . hash('sha256', $planRaw),
                'type' => 'class_rename_plan',
                'contract_version' => '1.0',
                'target_id' => $target,
            ],
            'runner' => [
                'name' => 'rename-plan',
                'dry_run' => false,
            ],
            'changed_files' => ['src/Service.php', 'src/RenamedService.php'],
            'changed_files_source' => 'git_status_diff',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        return $bundle;
    }

    private function sourceHash(AgentMapIndex $map, string $path): string
    {
        $file = $map->file($path);
        self::assertNotNull($file);

        return $file->sha256;
    }

    /** @return array<string, mixed> */
    private function json(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
