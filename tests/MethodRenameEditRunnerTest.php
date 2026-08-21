<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\MethodRenameEditRunner;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Rename\MethodRenamePlanner;

final class MethodRenameEditRunnerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-rename-apply-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', "<?php\nnamespace Demo;\nfinal class Service { public function save(): void {} }\n");
        file_put_contents($this->root . '/src/Caller.php', "<?php\nnamespace Demo;\nfinal class Caller { public function run(Service \$service): void { \$service->save(); } }\n");
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testChangedSourceAfterPlanningRejectsEveryEditBeforeMutation(): void
    {
        [$map, $plan] = $this->realPlan();
        $caller = $this->root . '/src/Caller.php';
        $declaration = (string) file_get_contents($this->root . '/src/Service.php');
        file_put_contents($caller, (string) file_get_contents($caller) . "// changed after planning\n");

        try {
            (new MethodRenameEditRunner())->preflight($plan, $map, $this->root);
            self::fail('Expected stale per-edit evidence to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('changed before apply', $exception->getMessage());
        }

        self::assertSame($declaration, file_get_contents($this->root . '/src/Service.php'));
        self::assertStringContainsString('->save()', (string) file_get_contents($caller));
    }

    /** @param callable(array<string, mixed>): array<string, mixed> $change */
    #[DataProvider('unsafePlans')]
    public function testUnsafePlanContractsCauseZeroMutation(callable $change, string $message): void
    {
        [$map, $plan] = $this->realPlan();
        $before = $this->sources();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);
        try {
            (new MethodRenameEditRunner())->preflight($change($plan), $map, $this->root);
        } finally {
            self::assertSame($before, $this->sources());
        }
    }

    /** @return iterable<string, array{callable(array<string, mixed>): array<string, mixed>, string}> */
    public static function unsafePlans(): iterable
    {
        yield 'unsupported contract' => [static function (array $plan): array { $plan['contract_version'] = '2.0'; return $plan; }, 'Unsupported'];
        yield 'semantic blocker' => [static function (array $plan): array { $plan['status'] = 'blocked'; $plan['blockers'] = ['ambiguous target']; return $plan; }, 'semantic blockers'];
        yield 'review required' => [static function (array $plan): array { $plan['status'] = 'review_required'; return $plan; }, 'explicit review'];
        yield 'already stale' => [static function (array $plan): array { $plan['status'] = 'blocked'; $plan['stale_evidence'] = [['path' => 'src/Caller.php', 'reason' => 'hash']]; return $plan; }, 'rebuild the map and re-plan'];
        yield 'contradictory provenance' => [static function (array $plan): array { $plan['provenance']['map_digest'] = 'sha256:wrong'; return $plan; }, 'current map identity'];
        yield 'source hash mismatch' => [static function (array $plan): array { $plan['edits'][0]['source_sha256'] = 'sha256:wrong'; return $plan; }, 'changed before apply'];
        yield 'expected token mismatch' => [static function (array $plan): array { $plan['edits'][0]['expected'] = 'other'; return $plan; }, 'changed before apply'];
        yield 'exact range mismatch' => [static function (array $plan): array { ++$plan['edits'][0]['start_file_pos']; return $plan; }, 'changed before apply'];
    }

    /** @return array{\voku\AgentMap\Index\AgentMapIndex, array<string, mixed>} */
    private function realPlan(): array
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $plan = (new MethodRenamePlanner())->plan($map, 'Demo\\Service::save', 'persist')->toArray();
        self::assertSame('safe', $plan['status']);

        return [$map, $plan];
    }

    /** @return array<string, string> */
    private function sources(): array
    {
        return [
            'caller' => (string) file_get_contents($this->root . '/src/Caller.php'),
            'service' => (string) file_get_contents($this->root . '/src/Service.php'),
        ];
    }
}
