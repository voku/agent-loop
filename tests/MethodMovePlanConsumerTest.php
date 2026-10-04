<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\Refactor\MethodMovePlanApplier;
use voku\AgentLoop\Edit\Refactor\RenamePlanEditEvidence;
use voku\AgentLoop\Tests\Support\CachedAgentMapBuilder;
use voku\AgentMap\Move\MethodMovePlanner;

final class MethodMovePlanConsumerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-method-move-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);

        file_put_contents($this->root . '/src/MethodSource.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class MethodSource
{
    private static function normalize(string $value): string
    {
        return trim($value);
    }
}
PHP);

        file_put_contents($this->root . '/src/MethodDestination.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class MethodDestination
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

    public function testReleasedMethodMovePlanAppliesThroughSharedTransaction(): void
    {
        [$map, $plan] = $this->realPlan();

        $prepared = (new MethodMovePlanApplier())->preflight($plan, $map, $this->root);
        self::assertSame('method_move_plan', $prepared['plan_type']);
        self::assertSame(2, $prepared['edit_count']);
        self::assertSame(0, $prepared['move_count']);

        $result = (new MethodMovePlanApplier())->apply($plan, $map, $this->root);

        self::assertTrue($result->succeeded());
        self::assertStringNotContainsString(
            'function normalize',
            (string) file_get_contents($this->root . '/src/MethodSource.php'),
        );
        self::assertStringContainsString(
            'private static function normalize',
            (string) file_get_contents($this->root . '/src/MethodDestination.php'),
        );
    }

    public function testDestinationIdentityTamperingFailsBeforeMutation(): void
    {
        [$map, $plan] = $this->realPlan();
        $before = $this->sources();
        $plan['destination_fqn'] = 'Fixture\\OtherDestination';

        try {
            (new MethodMovePlanApplier())->preflight($plan, $map, $this->root);
            self::fail('Tampered destination identity must fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('exact destination method identity', $exception->getMessage());
        }

        self::assertSame($before, $this->sources());
    }

    public function testMethodMoveRequiresPhpStanBackedCurrentMap(): void
    {
        [$map, $plan] = $this->realPlan();
        $structural = new \voku\AgentMap\Index\AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: $map->root,
            backend: 'simple-php-code-parser+structural-only',
            files: $map->files,
            relations: $map->relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a PHPStan-backed current map');
        (new MethodMovePlanApplier())->preflight($plan, $structural, $this->root);
    }

    public function testRenameEditEvidenceStillRejectsEmptyReplacementByDefault(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-empty string replacement');

        RenamePlanEditEvidence::fromArray([
            'path' => 'src/MethodSource.php',
            'source_sha256' => 'sha256:' . str_repeat('0', 64),
            'start_file_pos' => 0,
            'end_file_pos' => 0,
            'line_start' => 1,
            'line_end' => 1,
            'expected' => 'x',
            'replacement' => '',
            'role' => 'method_declaration',
            'symbol_id' => 'method:Fixture\\MethodSource::normalize',
            'resolution' => 'phpstan_resolved',
        ]);
    }

    /** @return array{\voku\AgentMap\Index\AgentMapIndex, array<string, mixed>} */
    private function realPlan(): array
    {
        $map = CachedAgentMapBuilder::build($this->root, ['src'], []);
        self::assertStringEndsWith('+phpstan', $map->backend);

        $plan = (new MethodMovePlanner())->plan(
            $map,
            'Fixture\\MethodSource::normalize',
            'Fixture\\MethodDestination',
        )->toArray();

        self::assertSame('method_move_plan', $plan['type']);
        self::assertSame('1.0', $plan['contract_version']);
        self::assertSame('safe', $plan['status']);
        self::assertSame([], $plan['stale_evidence']);
        self::assertSame([], $plan['blockers']);
        self::assertSame([], $plan['blind_spots']);
        self::assertSame([], $plan['owner_dependencies']);
        self::assertCount(2, $plan['edits']);

        return [$map, $plan];
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        return [
            'source' => (string) file_get_contents($this->root . '/src/MethodSource.php'),
            'destination' => (string) file_get_contents($this->root . '/src/MethodDestination.php'),
        ];
    }
}
