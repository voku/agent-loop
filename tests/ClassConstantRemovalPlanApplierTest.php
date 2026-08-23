<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\Refactor\ClassConstantRemovalPlanApplier;
use voku\AgentLoop\Edit\Refactor\ClassConstantRemovalPlanDocument;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;

final class ClassConstantRemovalPlanApplierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-class-constant-removal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    private const OBSOLETE = 'unused';

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

    public function testAppliesExactDeletionAndLeavesNoTemporaryArtifacts(): void
    {
        $map = $this->map();
        $plan = $this->plan($map);

        $result = (new ClassConstantRemovalPlanApplier())->apply($plan, $map, $this->root);

        self::assertTrue($result->succeeded());
        $source = (string) file_get_contents($this->root . '/src/Service.php');
        self::assertStringNotContainsString('OBSOLETE', $source);
        self::assertStringContainsString('public function run()', $source);
        self::assertSame([], $this->temporaryArtifacts());
    }

    public function testDocumentRejectsReviewRequiredPlanBeforeMutation(): void
    {
        $map = $this->map();
        $plan = $this->plan($map);
        $plan['status'] = 'review_required';
        $before = (string) file_get_contents($this->root . '/src/Service.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not safe');
        try {
            ClassConstantRemovalPlanDocument::fromArray($plan);
        } finally {
            self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));
        }
    }

    public function testStaleSourceHashLeavesSourceUntouched(): void
    {
        $map = $this->map();
        $plan = $this->plan($map);
        $plan['edits'][0]['source_sha256'] = 'sha256:' . str_repeat('0', 64);
        $before = (string) file_get_contents($this->root . '/src/Service.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evidence changed before apply');
        try {
            (new ClassConstantRemovalPlanApplier())->apply($plan, $map, $this->root);
        } finally {
            self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));
            self::assertSame([], $this->temporaryArtifacts());
        }
    }

    public function testPublicationFailureRestoresEverySource(): void
    {
        $map = $this->map();
        $plan = $this->plan($map);
        $before = (string) file_get_contents($this->root . '/src/Service.php');
        $applier = new ClassConstantRemovalPlanApplier(
            renameOperation: static function (string $from, string $to): bool {
                if (str_contains($from, '.agent-loop-class-constant-removal-stage-')) {
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
            self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));
            self::assertSame([], $this->temporaryArtifacts());
        }
    }

    public function testRejectsMalformedTargetIdentity(): void
    {
        $map = $this->map();
        $plan = $this->plan($map);
        $plan['target_id'] = 'class_constant:Demo\\Service::';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Class::CONSTANT syntax');
        ClassConstantRemovalPlanDocument::fromArray($plan);
    }

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build($this->root, ['src'], []);
    }

    /** @return array<string, mixed> */
    private function plan(AgentMapIndex $map): array
    {
        self::assertNotNull($map->fingerprint);
        $path = 'src/Service.php';
        $source = (string) file_get_contents($this->root . '/' . $path);
        $expected = "    private const OBSOLETE = 'unused';\n";
        $start = strpos($source, $expected);
        self::assertIsInt($start);

        return [
            'type' => 'class_constant_removal_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => 'class_constant:Demo\\Service::OBSOLETE',
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
                'role' => 'class_constant_declaration_removal',
                'symbol_id' => 'class_constant:Demo\\Service::OBSOLETE',
                'resolution' => 'parser_resolved',
            ]],
            'moves' => [],
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => ['Reflection and dynamic lookup remain outside the observable envelope.'],
        ];
    }

    /** @return list<string> */
    private function temporaryArtifacts(): array
    {
        $matches = glob($this->root . '/src/*.agent-loop-class-constant-removal-*');

        return is_array($matches) ? $matches : [];
    }
}
