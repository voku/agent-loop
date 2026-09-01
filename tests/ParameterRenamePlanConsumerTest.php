<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\Refactor\RenamePlanApplier;
use voku\AgentLoop\Edit\Refactor\RenamePlanDocument;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;

final class ParameterRenamePlanConsumerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-parameter-plan-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Demo;

final class Service
{
    private function greet(string $name): string
    {
        return 'Hello ' . $name;
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

    public function testParameterPlanUsesTheExistingTransactionalEditBoundary(): void
    {
        $map = $this->map();
        $target = 'method:Demo\\Service::greet';
        $plan = $this->plan($map, $target, [
            $this->edit($map, '$name', '$recipient', $target, 0, 'parameter_declaration'),
            $this->edit($map, '$name', '$recipient', $target, 1, 'parameter_binding'),
        ]);

        $result = (new RenamePlanApplier())->apply($plan, $map, $this->root);

        self::assertTrue($result->succeeded());
        self::assertStringContainsString('$recipient', (string) file_get_contents($this->root . '/src/Service.php'));
        self::assertStringNotContainsString('$name', (string) file_get_contents($this->root . '/src/Service.php'));
    }

    public function testParameterPlanAcceptsOnlyOwnerDeclaredFamilyEditIdentities(): void
    {
        $map = $this->map();
        $target = 'method:Demo\\Service::greet';
        $familyMember = 'method:Demo\\Contract::greet';
        $plan = $this->plan($map, $target, [
            $this->edit($map, '$name', '$recipient', $target . ',' . $familyMember),
        ], [$target, $familyMember]);

        $document = RenamePlanDocument::fromArray($plan);

        self::assertSame('parameter_rename_plan', $document->type);
        self::assertCount(1, $document->edits);
    }

    public function testParameterPlanRejectsEditIdentityOutsideOwnerDeclaredFamily(): void
    {
        $map = $this->map();
        $target = 'method:Demo\\Service::greet';
        $plan = $this->plan($map, $target, [
            $this->edit($map, '$name', '$recipient', 'method:Other\\Service::greet'),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not bound to the declared target identity');
        RenamePlanDocument::fromArray($plan);
    }

    public function testParameterPlanRejectsReviewRequiredWithoutMutation(): void
    {
        $map = $this->map();
        $target = 'method:Demo\\Service::greet';
        $plan = $this->plan($map, $target, [
            $this->edit($map, '$name', '$recipient', $target),
        ]);
        $plan['status'] = 'review_required';
        $before = (string) file_get_contents($this->root . '/src/Service.php');

        try {
            (new RenamePlanApplier())->apply($plan, $map, $this->root);
            self::fail('Review-required parameter plan must not apply.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('requires explicit review', $exception->getMessage());
        }

        self::assertSame($before, (string) file_get_contents($this->root . '/src/Service.php'));
    }

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build($this->root, ['src'], []);
    }

    /**
     * @param list<array<string, int|string>> $edits
     * @param list<string>|null $family
     * @return array<string, mixed>
     */
    private function plan(AgentMapIndex $map, string $target, array $edits, ?array $family = null): array
    {
        return [
            'type' => 'parameter_rename_plan',
            'contract_version' => '1.0',
            'status' => 'safe',
            'target_id' => $target,
            'original_name' => '$name',
            'replacement_name' => '$recipient',
            'parameter_index' => 0,
            'family' => $family ?? [$target],
            'provenance' => [
                'map_digest' => $map->mapDigest(),
                'backend' => $map->backend,
                'analysis_fingerprint' => $map->fingerprint?->toArray(),
            ],
            'edits' => $edits,
            'blind_spots' => [],
            'stale_evidence' => [],
            'blockers' => [],
            'not_observable' => [],
        ];
    }

    /** @return array<string, int|string> */
    private function edit(
        AgentMapIndex $map,
        string $needle,
        string $replacement,
        string $symbolId,
        int $occurrence = 0,
        string $role = 'parameter_declaration',
    ): array {
        $source = (string) file_get_contents($this->root . '/src/Service.php');
        $offset = 0;
        $start = false;
        for ($index = 0; $index <= $occurrence; ++$index) {
            $start = strpos($source, $needle, $offset);
            self::assertIsInt($start);
            $offset = $start + strlen($needle);
        }
        self::assertIsInt($start);
        $file = $map->file('src/Service.php');
        self::assertNotNull($file);

        return [
            'path' => 'src/Service.php',
            'source_sha256' => $file->sha256,
            'start_file_pos' => $start,
            'end_file_pos' => $start + strlen($needle) - 1,
            'line_start' => 1,
            'line_end' => 1,
            'expected' => $needle,
            'replacement' => $replacement,
            'role' => $role,
            'symbol_id' => $symbolId,
            'resolution' => 'parser_exact',
        ];
    }
}
