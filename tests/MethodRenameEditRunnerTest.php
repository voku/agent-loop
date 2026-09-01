<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\EditExecution;
use voku\AgentLoop\Edit\EditRequest;
use voku\AgentLoop\Edit\MethodRenameEditRunner;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Rename\MethodRenamePlanner;

final class MethodRenameEditRunnerTest extends TestCase
{
    private string $root;

    /** Creates an isolated two-file method-rename fixture. */
    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-rename-apply-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Service.php', "<?php\nnamespace Demo;\nfinal class Service { public function save(): void {} }\n");
        file_put_contents($this->root . '/src/Caller.php', "<?php\nnamespace Demo;\nfinal class Caller { public function run(Service \$service): void { \$service->save(); } }\n");
    }

    /** Removes the isolated method-rename fixture after each test. */
    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testChangedSourceAfterPlanningRejectsAtCurrentMapGateBeforeMutation(): void
    {
        [$map, $plan] = $this->realPlan();
        $caller = $this->root . '/src/Caller.php';
        $declaration = (string) file_get_contents($this->root . '/src/Service.php');
        file_put_contents($caller, (string) file_get_contents($caller) . "// changed after planning\n");

        try {
            (new MethodRenameEditRunner())->preflight($plan, $map, $this->root);
            self::fail('Expected stale current-map evidence to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Current agent-map source evidence is stale; rebuild the map before applying the refactor plan.',
                $exception->getMessage(),
            );
        }

        self::assertSame($declaration, file_get_contents($this->root . '/src/Service.php'));
        self::assertStringContainsString('->save()', (string) file_get_contents($caller));
    }

    public function testValidButWrongPerEditSourceHashRejectsBeforeMutation(): void
    {
        [$map, $plan] = $this->realPlan();
        $before = $this->sources();
        $plan['edits'][0]['source_sha256'] = 'sha256:' . str_repeat('0', 64);

        try {
            (new MethodRenameEditRunner())->preflight($plan, $map, $this->root);
            self::fail('Expected current per-edit hash evidence to fail closed.');
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Refactor edit evidence changed before apply; rebuild and re-plan.',
                $exception->getMessage(),
            );
        }

        self::assertSame($before, $this->sources());
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
        yield 'already stale' => [static function (array $plan): array { $plan['status'] = 'blocked'; $plan['stale_evidence'] = [['path' => 'src/Caller.php', 'reason' => 'hash']]; return $plan; }, 'stale evidence'];
        yield 'contradictory provenance' => [static function (array $plan): array { $plan['provenance']['map_digest'] = 'sha256:wrong'; return $plan; }, 'current map identity'];
        yield 'malformed source hash' => [static function (array $plan): array { $plan['edits'][0]['source_sha256'] = 'sha256:wrong'; return $plan; }, 'invalid source SHA-256'];
        yield 'expected token mismatch' => [static function (array $plan): array { $plan['edits'][0]['expected'] = 'other'; return $plan; }, 'changed before apply'];
        yield 'exact range mismatch' => [static function (array $plan): array { ++$plan['edits'][0]['start_file_pos']; return $plan; }, 'changed before apply'];
    }

    public function testSecondPublicationFailureRestoresEverySource(): void
    {
        $before = $this->sources();
        $service = $this->root . '/src/Service.php';
        $runner = new MethodRenameEditRunner(
            renameOperation: static function (string $from, string $to) use ($service): bool {
                if ($to === $service && str_contains($from, '.agent-loop-refactor-plan-stage-')) {
                    return false;
                }

                return rename($from, $to);
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('every source file was restored');
        try {
            $runner->run($this->execution());
        } finally {
            self::assertSame($before, $this->sources());
            self::assertSame([], $this->temporaryArtifacts());
        }
    }

    public function testSyntaxValidationFailureLeavesEverySourceUntouched(): void
    {
        $before = $this->sources();
        $runner = new MethodRenameEditRunner(
            lintOperation: static function (string $path): array {
                if (str_contains($path, 'Service.php.agent-loop-refactor-plan-stage-')) {
                    return ['exit_code' => 1, 'stdout' => '', 'stderr' => 'forced parser failure'];
                }

                return ['exit_code' => 0, 'stdout' => 'No syntax errors detected', 'stderr' => ''];
            },
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed syntax validation before publication');
        try {
            $runner->run($this->execution());
        } finally {
            self::assertSame($before, $this->sources());
            self::assertSame([], $this->temporaryArtifacts());
        }
    }

    /** @return array{\voku\AgentMap\Index\AgentMapIndex, array<string, mixed>} */
    private function realPlan(): array
    {
        $map = (new AgentMapBuilder())->build($this->root, ['src'], []);
        $plan = (new MethodRenamePlanner())->plan($map, 'Demo\\Service::save', 'persist')->toArray();
        self::assertSame('safe', $plan['status']);

        return [$map, $plan];
    }

    private function execution(): EditExecution
    {
        [$map] = $this->realPlan();
        $mapIndex = $this->root . '/map.json';
        (new IndexWriter())->write($map, $mapIndex);
        $request = new EditRequest(
            taskId: 'RENAME-TEST',
            target: 'Demo\\Service::save',
            instruction: 'Rename the proven method family.',
            projectRoot: $this->root,
            recallRoot: $this->root,
            mapIndex: $mapIndex,
            mapRoot: $this->root,
            outputDirectory: $this->root . '/edit',
            mapPaths: ['src'],
            runner: 'method-rename',
            replacementMethod: 'persist',
        );

        return new EditExecution($request, $this->root . '/prompt.md', $map->resolveMethod($request->target));
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
