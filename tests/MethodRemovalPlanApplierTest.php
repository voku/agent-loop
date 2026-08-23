<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\Refactor\MethodRemovalPlanApplier;
use voku\AgentLoop\Edit\Refactor\MethodRemovalPlanDocument;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Removal\MethodRemovalPlanner;

final class MethodRemovalPlanApplierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-method-removal-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
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

    public function testAppliesExactDeletionAndLeavesNoTemporaryArtifacts(): void
    {
        $map = $this->map();
        $plan = $this->plan($map);

        $result = (new MethodRemovalPlanApplier())->apply($plan, $map, $this->root);

        self::assertTrue($result->succeeded());
        $source = (string) file_get_contents($this->root . '/src/Service.php');
        self::assertStringNotContainsString('obsolete', $source);
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
            MethodRemovalPlanDocument::fromArray($plan);
        } finally {
            self::assertSame($before, file_get_contents($this->root . '/src/Service.php'));
        }
    }

    public function testSyntaxFailureLeavesSourceUntouched(): void
    {
        $map = $this->map();
        $plan = $this->plan($map);
        $before = (string) file_get_contents($this->root . '/src/Service.php');
        $applier = new MethodRemovalPlanApplier(
            lintOperation: static fn (string $path): array => [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => 'forced lint failure in ' . basename($path),
            ],
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('syntax validation');
        try {
            $applier->apply($plan, $map, $this->root);
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
        $applier = new MethodRemovalPlanApplier(
            renameOperation: static function (string $from, string $to): bool {
                if (str_contains($from, '.agent-loop-method-removal-stage-')) {
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

    private function map(): AgentMapIndex
    {
        return (new AgentMapBuilder())->build($this->root, ['src'], []);
    }

    /** @return array<string, mixed> */
    private function plan(AgentMapIndex $map): array
    {
        return (new MethodRemovalPlanner())->plan($map, 'Demo\\Service::obsolete')->toArray();
    }

    /** @return list<string> */
    private function temporaryArtifacts(): array
    {
        $matches = glob($this->root . '/src/*.agent-loop-method-removal-*');

        return is_array($matches) ? array_values($matches) : [];
    }
}
