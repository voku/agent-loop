<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\Refactor\ClassMovePlanApplier;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;

final class ClassMovePlanConsumerTest extends TestCase
{
    private string $root;

    /** Creates an isolated structural-only class-move fixture. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-class-move-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/Old', 0o775, true);
        file_put_contents($this->root . '/src/Old/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo\Old;

final class Service
{
}
PHP);
    }

    /** Removes the isolated class-move fixture after each test. */
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

    /** Proves a structural-only plan can create its missing in-root destination directory. */
    public function testClassMoveCreatesDestinationDirectoryAndAppliesWithStructuralOnlyMap(): void
    {
        $map = $this->structuralMap();
        $plan = $this->plan($map);

        self::assertDirectoryDoesNotExist($this->root . '/src/New');
        $result = (new ClassMovePlanApplier())->apply($plan, $map, $this->root);

        self::assertTrue($result->succeeded());
        self::assertFileDoesNotExist($this->root . '/src/Old/Service.php');
        self::assertDirectoryExists($this->root . '/src/New');
        self::assertFileExists($this->root . '/src/New/Service.php');
        self::assertStringContainsString('namespace Demo\\New;', (string) file_get_contents($this->root . '/src/New/Service.php'));
    }

    /** Proves a pre-existing destination rejects the plan before mutation. */
    public function testClassMoveDestinationCollisionFailsBeforeMutation(): void
    {
        $map = $this->structuralMap();
        $plan = $this->plan($map);
        mkdir($this->root . '/src/New', 0o775, true);
        file_put_contents($this->root . '/src/New/Service.php', "<?php\n// occupied\n");
        $before = (string) file_get_contents($this->root . '/src/Old/Service.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('destination already exists');
        try {
            (new ClassMovePlanApplier())->apply($plan, $map, $this->root);
        } finally {
            self::assertSame($before, (string) file_get_contents($this->root . '/src/Old/Service.php'));
            self::assertSame("<?php\n// occupied\n", (string) file_get_contents($this->root . '/src/New/Service.php'));
        }
    }

    /** Proves destination-absence evidence cannot be weakened by plan tampering. */
    public function testClassMoveRejectsTamperedDestinationPrecondition(): void
    {
        $map = $this->structuralMap();
        $plan = $this->plan($map);
        $plan['moves'][0]['destination_must_be_absent'] = false;
        $before = (string) file_get_contents($this->root . '/src/Old/Service.php');

        try {
            (new ClassMovePlanApplier())->apply($plan, $map, $this->root);
            self::fail('Tampered class move precondition must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('required to be absent', $exception->getMessage());
        }

        self::assertSame($before, (string) file_get_contents($this->root . '/src/Old/Service.php'));
        self::assertDirectoryDoesNotExist($this->root . '/src/New');
    }

    /** Proves publication rollback restores both source and newly created directory state. */
    public function testClassMovePublicationFailureRestoresSourceAndCreatedDirectoryState(): void
    {
        $map = $this->structuralMap();
        $plan = $this->plan($map);
        $before = (string) file_get_contents($this->root . '/src/Old/Service.php');
        $destination = str_replace('\\', '/', $this->root . '/src/New/Service.php');
        $applier = new ClassMovePlanApplier(
            renameOperation: static function (string $from, string $to) use ($destination): bool {
                if (str_replace('\\', '/', $to) === $destination) {
                    return false;
                }

                return rename($from, $to);
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('publication failed; every source file was restored');
        try {
            $applier->apply($plan, $map, $this->root);
        } finally {
            self::assertSame($before, (string) file_get_contents($this->root . '/src/Old/Service.php'));
            self::assertDirectoryDoesNotExist($this->root . '/src/New');
        }
    }

    /** Builds the structural-only Map snapshot used by class-move host tests. */
    private function structuralMap(): AgentMapIndex
    {
        $built = (new AgentMapBuilder())->build($this->root, ['src'], []);

        return new AgentMapIndex(
            schemaVersion: $built->schemaVersion,
            root: $built->root,
            backend: 'simple-php-code-parser+structural-only',
            files: $built->files,
            relations: $built->relations,
            diagnostics: $built->diagnostics,
            fingerprint: $built->fingerprint,
        );
    }

    /** @return array<string, mixed> */
    private function plan(AgentMapIndex $map): array
    {
        $path = 'src/Old/Service.php';
        $source = (string) file_get_contents($this->root . '/' . $path);
        $needle = 'Demo\\Old';
        $start = strpos($source, $needle);
        self::assertIsInt($start);
        $file = $map->file($path);
        self::assertNotNull($file);

        return [
            'type' => 'class_move_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => 'class:Demo\\Old\\Service',
            'source_fqn' => 'Demo\\Old\\Service',
            'destination_fqn' => 'Demo\\New\\Service',
            'provenance' => [
                'map_digest' => $map->mapDigest(),
                'backend' => $map->backend,
                'analysis_fingerprint' => $map->fingerprint?->toArray(),
            ],
            'autoload' => [
                'source_prefix' => 'Demo\\',
                'source_directory' => 'src',
                'destination_prefix' => 'Demo\\',
                'destination_directory' => 'src',
                'destination_path' => 'src/New/Service.php',
            ],
            'edits' => [[
                'path' => $path,
                'source_sha256' => $file->sha256,
                'start_file_pos' => $start,
                'end_file_pos' => $start + strlen($needle) - 1,
                'line_start' => 5,
                'line_end' => 5,
                'expected' => $needle,
                'replacement' => 'Demo\\New',
                'role' => 'namespace_declaration',
                'symbol_id' => 'class:Demo\\Old\\Service',
                'resolution' => 'parser_resolved',
            ]],
            'moves' => [[
                'from_path' => $path,
                'to_path' => 'src/New/Service.php',
                'source_sha256' => $file->sha256,
                'reason' => 'Fixture PSR-4 move.',
                'destination_must_be_absent' => true,
            ]],
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => [],
        ];
    }
}
