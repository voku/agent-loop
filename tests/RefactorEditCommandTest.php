<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use Closure;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Edit\EditCommand;
use voku\AgentLoop\Edit\EditMutationLock;
use voku\AgentLoop\Edit\Refactor\RefactorEditCommand;
use voku\AgentLoop\Edit\Refactor\RenamePlanApplier;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexWriter;

final class RefactorEditCommandTest extends TestCase
{
    private string $root;
    private AgentMapIndex $map;
    private string $mapPath;
    private string $planPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-refactor-command-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    public function oldName(): void
    {
    }
}
PHP);

        $this->map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $this->mapPath = $this->root . '/map.json';
        (new IndexWriter())->write($this->map, $this->mapPath);
        $this->planPath = $this->root . '/plan.json';
        file_put_contents($this->planPath, json_encode($this->plan(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
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

    public function testEditCommandRoutesRefactorDryRunBeforeMethodTargetParsing(): void
    {
        $before = (string) file_get_contents($this->root . '/src/Service.php');

        ob_start();
        $exit = (new EditCommand($this->root))->run([
            'refactor',
            $this->planPath,
            '--task=REFACTOR-DRY',
            '--map-index=' . $this->mapPath,
            '--dry-run',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('Refactor execution bundle prepared:', $output);
        self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));

        $execution = $this->execution('REFACTOR-DRY');
        self::assertSame('prepared', $execution['status']);
        self::assertSame('method_rename_plan', $execution['plan']['type']);
        self::assertSame('method:Demo\\Service::oldName', $execution['plan']['target_id']);
        self::assertSame('rename-plan', $execution['runner']['name']);
        self::assertTrue($execution['runner']['dry_run']);
        self::assertSame(0, $execution['runner']['model_input_tokens']);
        self::assertSame(0, $execution['runner']['model_tool_calls']);
    }

    public function testMutationPublicationRunsInsideSharedProjectLock(): void
    {
        $insideLock = false;
        $observedPublication = false;
        $lock = new EditMutationLock(
            synchronizeOperation: static function (string $projectRoot, Closure $operation) use (&$insideLock): mixed {
                self::assertNotSame('', $projectRoot);
                self::assertFalse($insideLock);
                $insideLock = true;
                try {
                    return $operation();
                } finally {
                    $insideLock = false;
                }
            },
        );
        $applier = new RenamePlanApplier(
            renameOperation: static function (string $from, string $to) use (&$insideLock, &$observedPublication): bool {
                self::assertTrue($insideLock, 'rename-plan publication must stay inside the shared edit mutation lock');
                if (str_contains($from, '.agent-loop-rename-plan-stage-')) {
                    $observedPublication = true;
                }

                return rename($from, $to);
            },
        );
        $command = new RefactorEditCommand($this->root, applier: $applier, mutationLock: $lock);

        ob_start();
        $exit = $command->run([
            $this->planPath,
            '--task=REFACTOR-LOCK',
            '--map-index=' . $this->mapPath,
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertTrue($observedPublication);
        self::assertFalse($insideLock);
        self::assertStringContainsString('function newName()', (string) file_get_contents($this->root . '/src/Service.php'));
        self::assertSame('runner_succeeded', $this->execution('REFACTOR-LOCK')['status']);
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        $source = (string) file_get_contents($this->root . '/src/Service.php');
        $start = strpos($source, 'oldName');
        self::assertIsInt($start);
        $file = $this->map->file('src/Service.php');
        self::assertNotNull($file);
        $target = 'method:Demo\\Service::oldName';

        return [
            'type' => 'method_rename_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => $target,
            'provenance' => [
                'map_digest' => $this->map->mapDigest(),
                'backend' => $this->map->backend,
                'analysis_fingerprint' => $this->map->fingerprint?->toArray(),
            ],
            'edits' => [[
                'path' => 'src/Service.php',
                'source_sha256' => $file->sha256,
                'start_file_pos' => $start,
                'end_file_pos' => $start + strlen('oldName') - 1,
                'line_start' => 9,
                'line_end' => 9,
                'expected' => 'oldName',
                'replacement' => 'newName',
                'role' => 'declaration',
                'symbol_id' => $target,
                'resolution' => 'parser_resolved',
            ]],
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => [],
        ];
    }

    /** @return array<string, mixed> */
    private function execution(string $taskId): array
    {
        $raw = file_get_contents($this->root . '/.agent-loop/edit/' . $taskId . '/execution.json');
        self::assertIsString($raw);
        $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
