<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Edit\EditMapPreparer;
use voku\AgentLoop\Edit\EditRequest;
use voku\AgentLoop\Init\InitConfigLoader;
use voku\AgentLoop\Map\ProjectMapBuilder;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Tests\Support\ManualProfileLifecycleFixture;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowRunPreparer;
use voku\AgentMap\Extract\TypeScriptSymbolExtractor;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;

/**
 * A project that declares `map.languages` beyond PHP gets Map-backed facts for those files.
 *
 * The tests that run the TypeScript compiler need Node.js and `<agent-loop>/node_modules/typescript`
 * (`npm ci`). Without them they skip locally and fail when `AGENT_MAP_REQUIRE_TYPESCRIPT=1`, the
 * same switch agent-map's own suite uses.
 */
#[Group('slow')]
final class TypeScriptMapSupportTest extends TestCase
{
    private const string MATCHER = "export interface Gesture {\n    name: string;\n}\n\nexport function matchGesture(input: string): Gesture | null {\n    return input === '' ? null : { name: input };\n}\n\nexport class Recognizer {\n    recognize(input: string): Gesture | null {\n        return matchGesture(input);\n    }\n}\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) realpath(sys_get_temp_dir()) . '/agent-loop-typescript-map-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/src/matcher.ts', self::MATCHER);
        file_put_contents($this->root . '/src/other.ts', "export const LIMIT = 3;\n");
        file_put_contents($this->root . '/README.md', "# Demo\n");
    }

    protected function tearDown(): void
    {
        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV);
        if (!is_dir($this->root)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testMapLanguagesDefaultToPhpAndAcceptTheOwnersLanguages(): void
    {
        self::assertSame(['php'], (new ProjectLayout($this->root))->mapLanguages());

        // Declared in any order; returned in agent-map's canonical order.
        $this->declareLanguages(['typescript', 'php']);
        self::assertSame(['php', 'typescript'], (new ProjectLayout($this->root))->mapLanguages());

        $this->declareLanguages(['typescript']);
        self::assertSame(['typescript'], (new ProjectLayout($this->root))->mapLanguages());
    }

    public function testAnUnknownMapLanguageFailsClosedInsteadOfIndexingTheDefault(): void
    {
        $this->declareLanguages(['typescript', 'rust']);

        $config = (new InitConfigLoader($this->root))->load('.agent-loop/init.json');
        self::assertSame(
            ['[WARN] init config: map.languages is invalid. Unknown language: rust. Supported: php, typescript.'],
            $config['warnings'],
        );

        try {
            (new ProjectLayout($this->root))->mapLanguages();
            self::fail('an unknown map language must be refused');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unknown language: rust. Supported: php, typescript.', $exception->getMessage());
            self::assertStringContainsString('.agent-loop/init.json', $exception->getMessage());
        }

        // Every Loop path that builds or requires the map refuses with it.
        $this->approvedContract('TS-BAD', ['src/matcher.ts']);
        try {
            (new WorkflowRunPreparer($this->root))->reconcileDiscovery((new TaskContractStore($this->root))->load('TS-BAD'));
            self::fail('Run preparation must refuse an invalid map.languages declaration');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unknown language: rust', $exception->getMessage());
        }
        self::assertSame(1, $this->dispatch(['map', 'build', '--paths=src'])[0]);
        self::assertFileDoesNotExist($this->mapIndex());
    }

    /** @return iterable<string, array{string}> */
    public static function malformedMapSettings(): iterable
    {
        yield 'map is not an object' => ['{"map": ["typescript"]}'];
        yield 'languages is a string' => ['{"map": {"languages": "typescript"}}'];
        yield 'languages is empty' => ['{"map": {"languages": []}}'];
        yield 'languages holds a non-string' => ['{"map": {"languages": ["php", 1]}}'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedMapSettings')]
    public function testAMalformedMapDeclarationIsRefused(string $config): void
    {
        file_put_contents($this->root . '/.agent-loop/init.json', $config);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid map configuration in .agent-loop/init.json');
        (new ProjectLayout($this->root))->mapLanguages();
    }

    public function testWhichScopeNeedsAMapFollowsTheDeclaredLanguages(): void
    {
        $php = new ProjectMapBuilder($this->root, ['php']);
        self::assertTrue($php->requiresMap('src/Greeter.php'));
        self::assertFalse($php->requiresMap('src/matcher.ts'), 'a PHP-only project never pays for a TypeScript map');
        self::assertFalse($php->requiresMap('templates/view.phtml'), 'the PHP trigger is unchanged: .php only');
        self::assertFalse($php->indexesBeyondPhp());

        $typeScript = new ProjectMapBuilder($this->root, ['typescript']);
        self::assertTrue($typeScript->requiresMap('src/matcher.ts'));
        self::assertTrue($typeScript->requiresMap('test/matcher.test.mjs'));
        self::assertFalse($typeScript->requiresMap('src/types.d.ts'), 'agent-map never indexes declaration files');
        self::assertFalse($typeScript->requiresMap('src/Greeter.php'));
        self::assertFalse($typeScript->requiresMap('README.md'));
        self::assertTrue($typeScript->indexesBeyondPhp());

        $mixed = new ProjectMapBuilder($this->root, ['php', 'typescript']);
        self::assertTrue($mixed->requiresMap('src/Greeter.php'));
        self::assertTrue($mixed->requiresMap('src/matcher.ts'));
        self::assertFalse($mixed->requiresMap('docs/guide.md'));
    }

    public function testAPhpOnlyProjectBuildsNoMapForTypeScriptScope(): void
    {
        $this->approvedContract('PHP-ONLY', ['src/matcher.ts']);

        $this->enter('PHP-ONLY');

        self::assertFileDoesNotExist($this->mapIndex(), 'without map.languages a .ts scope behaves as before');
    }

    public function testMarkdownOnlyScopeBuildsNoMapInATypeScriptProject(): void
    {
        $this->declareLanguages(['typescript']);
        $this->approvedContract('DOC-1', ['README.md']);

        $this->enter('DOC-1');

        self::assertFileDoesNotExist($this->mapIndex(), 'non-code work must not pay for Map discovery');
    }

    public function testEnterPreparesACurrentMapForTypeScriptScopeAndRecallReceivesIt(): void
    {
        $this->requireTypeScript();
        $this->declareLanguages(['typescript']);
        $this->approvedContract('TS-1', ['src/matcher.ts']);

        $this->enter('TS-1');

        $map = (new IndexReader())->read($this->mapIndex());
        self::assertSame('typescript-compiler+structural-only', $map->backend);
        $file = $map->file('src/matcher.ts');
        self::assertNotNull($file, 'the scoped TypeScript file must be indexed');
        self::assertContains('src/matcher.ts#matchGesture', array_map(static fn ($symbol): string => $symbol->fqn, $file->symbols));
        self::assertNull($map->file('src/other.ts'), 'a missing map is built from the Contract scope only');

        ob_start();
        try {
            $exit = (new Dispatcher($this->root))->run(['agent-loop', 'workflow', 'context', 'TS-1']);
            $context = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit);
        self::assertStringContainsString('src/matcher.ts#matchGesture', $context, 'Recall/context must cite the TypeScript symbol');
    }

    public function testATypeScriptChangeInvalidatesTheMapAndPreparationRebuildsAllOfIt(): void
    {
        $this->requireTypeScript();
        $this->declareLanguages(['typescript']);
        // A project-wide map, as `agent-loop map build --paths=src` leaves it.
        [$exit, $output] = $this->dispatch(['map', 'build', '--paths=src']);
        self::assertSame(0, $exit, $output);
        self::assertNotNull((new IndexReader())->read($this->mapIndex())->file('src/other.ts'));

        $this->approvedContract('TS-2', ['src/matcher.ts']);
        $contract = (new TaskContractStore($this->root))->load('TS-2');
        $preparer = new WorkflowRunPreparer($this->root);
        self::assertSame('ready', $preparer->discoveryReadiness($contract)->mapState);

        file_put_contents($this->root . '/src/matcher.ts', self::MATCHER . "\nexport function added(): number {\n    return 1;\n}\n");

        self::assertSame('stale', $this->readiness()->mapState, 'a changed TypeScript file must never read as current');
        try {
            $preparer->discoveryReadiness($contract);
            self::fail('a stale TypeScript map must block governed preparation until it is rebuilt');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Existing map scope (typescript) is not covered by a fresh agent-map snapshot', $exception->getMessage());
            self::assertStringContainsString(
                'agent-loop map build --languages=typescript --paths=src',
                $exception->getMessage(),
                'the named build keeps the scope the map was built from',
            );
            self::assertStringNotContainsString('--paths=src/matcher.ts', $exception->getMessage());
            self::assertStringNotContainsString('map refresh', $exception->getMessage(), 'refresh is PHP-only and would refuse');
        }

        $readiness = $preparer->reconcileDiscovery($contract);

        self::assertSame('ready', $readiness->mapState);
        $map = (new IndexReader())->read($this->mapIndex());
        $file = $map->file('src/matcher.ts');
        self::assertNotNull($file);
        self::assertContains('src/matcher.ts#added', array_map(static fn ($symbol): string => $symbol->fqn, $file->symbols));
        self::assertNotNull($map->file('src/other.ts'), 'the full rebuild keeps the scope the map was built from');
    }

    public function testAMixedProjectIndexesPhpAndTypeScriptScopeTogether(): void
    {
        $this->requireTypeScript();
        $this->declareLanguages(['php', 'typescript']);
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Consumer;\n\nfinal class Greeter\n{\n    public function greet(): string\n    {\n        return 'hi';\n    }\n}\n");
        $this->approvedContract('MIX-1', ['src/Greeter.php', 'src/matcher.ts']);

        $readiness = (new WorkflowRunPreparer($this->root))->reconcileDiscovery((new TaskContractStore($this->root))->load('MIX-1'));

        self::assertSame('ready', $readiness->mapState);
        $map = (new IndexReader())->read($this->mapIndex());
        self::assertSame('simple-php-code-parser+typescript-compiler+structural-only', $map->backend);
        self::assertNotNull($map->file('src/Greeter.php'));
        self::assertNotNull($map->file('src/matcher.ts'));
    }

    public function testAStaleMixedMapIsRebuiltWithTheSemanticBackendItCarries(): void
    {
        $this->requireTypeScript();
        $this->declareLanguages(['php', 'typescript']);
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nnamespace Consumer;\n\nfinal class Greeter\n{\n    public function greet(): string\n    {\n        return 'hi';\n    }\n}\n");
        [$exit, $output] = $this->dispatch(['map', 'build', '--paths=src', '--backend=phpstan']);
        self::assertSame(0, $exit, $output);
        self::assertSame('simple-php-code-parser+typescript-compiler+phpstan', (new IndexReader())->read($this->mapIndex())->backend);

        file_put_contents($this->root . '/src/other.ts', "export const LIMIT = 4;\nexport const EXTRA = 1;\n");
        $this->approvedContract('MIX-2', ['src/Greeter.php']);

        $readiness = (new WorkflowRunPreparer($this->root))->reconcileDiscovery((new TaskContractStore($this->root))->load('MIX-2'));

        self::assertSame('ready', $readiness->mapState, 'a stale TypeScript entry invalidates the map for PHP scope too');
        $map = (new IndexReader())->read($this->mapIndex());
        self::assertSame('simple-php-code-parser+typescript-compiler+phpstan', $map->backend);
        $other = $map->file('src/other.ts');
        self::assertNotNull($other);
        self::assertContains('src/other.ts#EXTRA', array_map(static fn ($symbol): string => $symbol->fqn, $other->symbols));
    }

    public function testAMissingTypeScriptToolchainBlocksPreparationWithTheOwnersReason(): void
    {
        $this->declareLanguages(['typescript']);
        // An explicit compiler path is the only candidate agent-map tries; this one does not exist.
        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV . '=' . $this->root . '/no-such-compiler');
        $this->approvedContract('TS-3', ['src/matcher.ts']);

        try {
            (new WorkflowRunPreparer($this->root))->reconcileDiscovery((new TaskContractStore($this->root))->load('TS-3'));
            self::fail('an unbuildable map blocks preparation, exactly as an unbuildable PHP map does');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('agent-map could not prepare discovery for this Contract', $exception->getMessage());
            self::assertStringContainsString('declared languages (typescript, from map.languages in .agent-loop/init.json)', $exception->getMessage());
            self::assertStringContainsString('Parsing failed for src/matcher.ts', $exception->getMessage(), "agent-map's own reason must be surfaced");
            self::assertStringContainsString('needs Node.js on PATH and the `typescript` npm package', $exception->getMessage());
            self::assertStringContainsString('Retry: agent-loop map build --languages=typescript --paths=src/matcher.ts', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->mapIndex(), 'a failed build publishes nothing');
    }

    public function testMapBuildUsesTheDeclaredLanguagesAndAnExplicitOverrideWins(): void
    {
        $this->requireTypeScript();
        $this->declareLanguages(['typescript']);
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nfinal class Greeter {}\n");

        [$exit, $output] = $this->dispatch(['map', 'build', '--paths=src']);
        self::assertSame(0, $exit, $output);
        $map = (new IndexReader())->read($this->mapIndex());
        self::assertSame('typescript', $map->file('src/matcher.ts')?->language);
        self::assertNull($map->file('src/Greeter.php'), 'php was not declared');

        [$exit, $output] = $this->dispatch(['map', 'build', '--paths=src', '--languages=php', '--backend=structural']);
        self::assertSame(0, $exit, $output);
        $map = (new IndexReader())->read($this->mapIndex());
        self::assertSame('simple-php-code-parser+structural-only', $map->backend);
        self::assertNotNull($map->file('src/Greeter.php'));
        self::assertNull($map->file('src/matcher.ts'));
    }

    public function testMapBuildUsesPersistedPathsAndAnExplicitPathOverrideWins(): void
    {
        $this->requireTypeScript();
        mkdir($this->root . '/lib', 0o775, true);
        file_put_contents($this->root . '/lib/only.ts', "export const LIB = 1;\n");
        $this->declareMapSettings(['typescript'], ['lib']);

        [$exit, $output] = $this->dispatch(['map', 'build']);
        self::assertSame(0, $exit, $output);
        $configured = (new IndexReader())->read($this->mapIndex());
        self::assertNotNull($configured->file('lib/only.ts'));
        self::assertNull($configured->file('src/matcher.ts'));

        [$exit, $output] = $this->dispatch(['map', 'build', '--paths=src']);
        self::assertSame(0, $exit, $output);
        $overridden = (new IndexReader())->read($this->mapIndex());
        self::assertNotNull($overridden->file('src/matcher.ts'));
        self::assertNull($overridden->file('lib/only.ts'));
    }

    public function testInvalidPersistedPathsFailClosedButAnExplicitPathCanOverrideThem(): void
    {
        $this->declareMapSettings(['typescript'], ['../outside']);

        [$exit, $output] = $this->dispatch(['map', 'build']);
        self::assertSame(1, $exit);
        self::assertFileDoesNotExist($this->mapIndex());

        $this->requireTypeScript();
        [$exit, $output] = $this->dispatch(['map', 'build', '--paths=src']);
        self::assertSame(0, $exit, $output);
        $map = (new IndexReader())->read($this->mapIndex());
        self::assertNotNull($map->file('src/matcher.ts'));
    }

    public function testDeterministicEditRefusesATypeScriptTarget(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('agent-loop edit supports PHP methods only; "src/matcher.ts#Recognizer::recognize" names a typescript symbol.');

        (new EditMapPreparer())->prepare($this->editRequest('src/matcher.ts#Recognizer::recognize'));
    }

    public function testDeterministicEditRefusesAMapThatIndexesTypeScriptWithoutReplacingIt(): void
    {
        mkdir(dirname($this->mapIndex()), 0o775, true);
        (new IndexWriter())->write(new AgentMapIndex(
            schemaVersion: '2.1',
            root: $this->root,
            backend: 'simple-php-code-parser+typescript-compiler+structural-only',
            files: [],
            relations: [],
            diagnostics: [],
        ), $this->mapIndex());
        $before = (string) file_get_contents($this->mapIndex());

        foreach ([false, true] as $forceRebuild) {
            try {
                (new EditMapPreparer())->prepare($this->editRequest('Greeter::greet', $forceRebuild));
                self::fail('deterministic edit must refuse a map that indexes TypeScript');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('agent-loop edit supports PHP-only maps', $exception->getMessage());
                self::assertStringContainsString('indexes php, typescript', $exception->getMessage());
            }
            self::assertSame($before, (string) file_get_contents($this->mapIndex()), 'the refusal must not rebuild over the map');
        }
    }

    private function editRequest(string $target, bool $forceMapRebuild = false): EditRequest
    {
        return new EditRequest(
            taskId: 'EDIT-1',
            target: $target,
            instruction: 'Change it.',
            projectRoot: $this->root,
            recallRoot: $this->root . '/.agent-loop/recall',
            mapIndex: $this->mapIndex(),
            mapRoot: $this->root,
            outputDirectory: $this->root . '/.agent-loop/edit',
            forceMapRebuild: $forceMapRebuild,
        );
    }

    /** @param list<string> $languages */
    private function declareLanguages(array $languages): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'map' => ['languages' => $languages]], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<string> $languages
     * @param list<string> $paths
     */
    private function declareMapSettings(array $languages, array $paths): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode(['version' => 1, 'map' => ['languages' => $languages, 'paths' => $paths]], JSON_THROW_ON_ERROR),
        );
    }

    /** @param list<string> $scope */
    private function approvedContract(string $taskId, array $scope): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create($taskId, 'Change existing behaviour.', $scope, [], ['php -r "exit(0);"'], 'planner');
        $contracts->approve($taskId, 'approver', $contracts->load($taskId)->revision);
    }

    private function mapIndex(): string
    {
        return $this->root . '/.agent-loop/map/php-symbols.json';
    }

    private function readiness(): \voku\AgentMap\Inspect\MapReadiness
    {
        return (new MapReadinessInspector())->inspect(MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map'));
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{int, string}
     */
    private function dispatch(array $tokens): array
    {
        ob_start();
        try {
            $exit = (new Dispatcher($this->root))->run(['agent-loop', ...$tokens]);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return [$exit, $output];
    }

    private function enter(string $taskId): void
    {
        ManualProfileLifecycleFixture::beforeEnter($this->root, $taskId);
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('enter', [$taskId, '--format=json']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, $output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['mutation_ready'] ?? null, 'enter reported no mutation readiness');
    }

    /**
     * Points agent-map at this repository's development compiler; the fixture has no node_modules.
     */
    private function requireTypeScript(): void
    {
        $compiler = dirname(__DIR__) . '/node_modules/typescript';
        if (!is_file($compiler . '/package.json')) {
            $this->unavailable('The typescript package is not installed in ' . dirname($compiler) . ' (run `npm ci`).');
        }
        exec('node --version 2>/dev/null', $output, $exitCode);
        if ($exitCode !== 0) {
            $this->unavailable('Node.js is not available on PATH.');
        }

        putenv(TypeScriptSymbolExtractor::TYPESCRIPT_PATH_ENV . '=' . $compiler);
    }

    /**
     * Skips locally; fails where the toolchain is promised (CI sets AGENT_MAP_REQUIRE_TYPESCRIPT=1).
     */
    private function unavailable(string $reason): never
    {
        if (getenv('AGENT_MAP_REQUIRE_TYPESCRIPT') === '1') {
            self::fail($reason . ' AGENT_MAP_REQUIRE_TYPESCRIPT=1 forbids skipping the TypeScript map tests.');
        }

        self::markTestSkipped($reason);
    }
}
