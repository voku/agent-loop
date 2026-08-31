<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\Refactor\RenamePlanApplier;
use voku\AgentLoop\Edit\Refactor\RenamePlanDocument;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;

final class RenamePlanApplierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-refactor-applier-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    public const LEGACY = 'legacy';

    private string $value = 'x';

    public function oldName(): void
    {
    }
}

function old_function(): void
{
}
PHP);
        file_put_contents($this->root . '/src/Caller.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Caller
{
    public function create(): Service
    {
        return new Service();
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

    /** @param array{type: string, target: string, path: string, needle: string, replacement: string} $case */
    #[DataProvider('renameKinds')]
    public function testAllFirstPartyRenameKindsUseOneExactEditBoundary(array $case): void
    {
        $map = $this->map();
        $plan = $this->plan(
            $map,
            $case['type'],
            $case['target'],
            [$this->edit($map, $case['path'], $case['needle'], $case['replacement'], $case['target'])],
        );

        $result = (new RenamePlanApplier())->apply($plan, $map, $this->root);

        self::assertTrue($result->succeeded());
        self::assertStringContainsString($case['replacement'], (string) file_get_contents($this->root . '/' . $case['path']));
        self::assertSame([], $this->temporaryArtifacts());
    }

    /** @return iterable<string, array{array{type: string, target: string, path: string, needle: string, replacement: string}}> */
    public static function renameKinds(): iterable
    {
        yield 'method' => [[
            'type' => 'method_rename_plan',
            'target' => 'method:Demo\\Service::oldName',
            'path' => 'src/Service.php',
            'needle' => 'oldName',
            'replacement' => 'newName',
        ]];
        yield 'function' => [[
            'type' => 'function_rename_plan',
            'target' => 'function:Demo\\old_function',
            'path' => 'src/Service.php',
            'needle' => 'old_function',
            'replacement' => 'new_function',
        ]];
        yield 'class' => [[
            'type' => 'class_rename_plan',
            'target' => 'class:Demo\\Service',
            'path' => 'src/Service.php',
            'needle' => 'Service',
            'replacement' => 'RenamedService',
        ]];
        yield 'property' => [[
            'type' => 'property_rename_plan',
            'target' => 'property:Demo\\Service::$value',
            'path' => 'src/Service.php',
            'needle' => 'value',
            'replacement' => 'renamedValue',
        ]];
        yield 'class constant' => [[
            'type' => 'class_constant_rename_plan',
            'target' => 'class_constant:Demo\\Service::LEGACY',
            'path' => 'src/Service.php',
            'needle' => 'LEGACY',
            'replacement' => 'CURRENT',
        ]];
    }

    public function testLoadedDocumentRejectsEditBoundToDifferentTarget(): void
    {
        $map = $this->map();
        $target = 'method:Demo\\Service::oldName';
        $edit = $this->edit($map, 'src/Service.php', 'oldName', 'newName', 'method:Demo\\Service::other');
        $plan = $this->plan($map, 'method_rename_plan', $target, [$edit]);
        $before = $this->sources();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not bound to the declared target identity');
        try {
            RenamePlanDocument::fromArray($plan);
        } finally {
            self::assertSame($before, $this->sources());
        }
    }

    public function testSyntaxFailureLeavesEverySourceUntouched(): void
    {
        $map = $this->map();
        $target = 'function:Demo\\old_function';
        $plan = $this->plan(
            $map,
            'function_rename_plan',
            $target,
            [$this->edit($map, 'src/Service.php', 'old_function', 'new_function', $target)],
        );
        $before = $this->sources();
        $applier = new RenamePlanApplier(
            lintOperation: static fn (string $path): array => [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'forced syntax failure in ' . basename($path),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed syntax validation before publication');
        try {
            $applier->apply($plan, $map, $this->root);
        } finally {
            self::assertSame($before, $this->sources());
            self::assertSame([], $this->temporaryArtifacts());
        }
    }

    public function testClassMoveAndCrossFileEditRollbackTogether(): void
    {
        $map = $this->map();
        $target = 'class:Demo\\Service';
        $plan = $this->plan(
            $map,
            'class_rename_plan',
            $target,
            [
                $this->edit($map, 'src/Caller.php', 'Service', 'RenamedService', $target),
                $this->edit($map, 'src/Service.php', 'Service', 'RenamedService', $target),
            ],
            [[
                'from_path' => 'src/Service.php',
                'to_path' => 'src/RenamedService.php',
                'source_sha256' => $this->sourceHash($map, 'src/Service.php'),
                'reason' => 'Conventional same-directory class file move.',
            ]],
        );
        $before = $this->sources();
        $destination = $this->root . '/src/RenamedService.php';
        $applier = new RenamePlanApplier(
            renameOperation: static function (string $from, string $to) use ($destination): bool {
                if ($to === $destination && str_contains($from, '.agent-loop-refactor-plan-stage-')) {
                    return false;
                }

                return rename($from, $to);
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('every source file was restored');
        try {
            $applier->apply($plan, $map, $this->root);
        } finally {
            self::assertSame($before, $this->sources());
            self::assertFileDoesNotExist($destination);
            self::assertSame([], $this->temporaryArtifacts());
        }
    }

    public function testClassMovePublishesEditedFileAtNewPath(): void
    {
        $map = $this->map();
        $target = 'class:Demo\\Service';
        $plan = $this->plan(
            $map,
            'class_rename_plan',
            $target,
            [$this->edit($map, 'src/Service.php', 'Service', 'RenamedService', $target)],
            [[
                'from_path' => 'src/Service.php',
                'to_path' => 'src/RenamedService.php',
                'source_sha256' => $this->sourceHash($map, 'src/Service.php'),
                'reason' => 'Conventional same-directory class file move.',
            ]],
        );

        $result = (new RenamePlanApplier())->apply($plan, $map, $this->root);

        self::assertTrue($result->succeeded());
        self::assertFileDoesNotExist($this->root . '/src/Service.php');
        self::assertFileExists($this->root . '/src/RenamedService.php');
        self::assertStringContainsString('class RenamedService', (string) file_get_contents($this->root . '/src/RenamedService.php'));
        self::assertSame([], $this->temporaryArtifacts());
    }

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build($this->root, ['src'], []);
    }

    /**
     * @param list<array<string, int|string>> $edits
     * @param list<array<string, string>> $moves
     * @return array<string, mixed>
     */
    private function plan(AgentMapIndex $map, string $type, string $target, array $edits, array $moves = []): array
    {
        return [
            'type' => $type,
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => $target,
            'provenance' => [
                'map_digest' => $map->mapDigest(),
                'backend' => $map->backend,
                'analysis_fingerprint' => $map->fingerprint?->toArray(),
            ],
            'edits' => $edits,
            'moves' => $moves,
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => [],
        ];
    }

    /** @return array<string, int|string> */
    private function edit(AgentMapIndex $map, string $path, string $needle, string $replacement, string $target): array
    {
        $source = (string) file_get_contents($this->root . '/' . $path);
        $start = strpos($source, $needle);
        self::assertIsInt($start, 'Fixture token must exist exactly enough for the focused test.');

        return [
            'path' => $path,
            'source_sha256' => $this->sourceHash($map, $path),
            'start_file_pos' => $start,
            'end_file_pos' => $start + strlen($needle) - 1,
            'line_start' => 1,
            'line_end' => 1,
            'expected' => $needle,
            'replacement' => $replacement,
            'role' => 'test_exact_token',
            'symbol_id' => $target,
            'resolution' => 'parser_resolved',
        ];
    }

    private function sourceHash(AgentMapIndex $map, string $path): string
    {
        $file = $map->file($path);
        self::assertNotNull($file);

        return $file->sha256;
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        return [
            'caller' => (string) file_get_contents($this->root . '/src/Caller.php'),
            'service' => (string) file_get_contents($this->root . '/src/Service.php'),
        ];
    }

    /** @return list<string> */
    private function temporaryArtifacts(): array
    {
        $matches = glob($this->root . '/src/*.agent-loop-refactor-plan-*');

        return is_array($matches) ? $matches : [];
    }
}
