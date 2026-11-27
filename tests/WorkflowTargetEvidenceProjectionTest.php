<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Map\ProjectMapBuilder;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowTargetEvidenceProjector;
use voku\AgentMap\IO\SourceLanguage;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;

final class WorkflowTargetEvidenceProjectionTest extends TestCase
{
    private const string CONTRACT_SOURCE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Contract
{
    public function __construct(public ?string $baseCommit = null)
    {
    }
}
PHP;

    private const string STORE_SOURCE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture;

final class Store
{
    public function create(string $base): Contract
    {
        return new Contract($base);
    }
}
PHP;

    private const string STORE_TEST_SOURCE = <<<'PHP'
<?php

declare(strict_types=1);

namespace Fixture\Tests;

use Fixture\Contract;
use Fixture\Store;

final class StoreTest
{
    public function testStoresTheBase(): void
    {
        $contract = (new Store())->create('abc');
        assert($contract->baseCommit === 'abc');
    }

    public function testStoresAnotherBase(Contract $contract): void
    {
        assert($contract->baseCommit !== null);
    }
}
PHP;

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-target-evidence-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create test root: ' . $this->root);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testBeforeApprovalAPropertyTargetListsTheTestsThatReadItWithoutChangingThePlanOrState(): void
    {
        $this->writeFixture();
        $this->buildMap();
        $before = $this->stateSnapshot();
        $baseline = $this->enter('TARGET-PLAN');

        $result = $this->enter('TARGET-PLAN', ['--target', 'Fixture\Contract::$baseCommit']);

        self::assertSame($before, $this->stateSnapshot(), 'enter --target must not create or change workflow state.');
        self::assertSame($baseline['payload']['next_action'], $result['payload']['next_action']);
        self::assertSame($baseline['payload']['next_action_kind'], $result['payload']['next_action_kind']);
        self::assertSame($baseline['exit'], $result['exit']);

        $evidence = $result['payload']['references']['target_evidence'];
        self::assertSame('ready', $evidence['status']);
        self::assertSame('none', $evidence['authority']);
        self::assertSame('current_structural_evidence', $evidence['evidence_status']);
        self::assertArrayNotHasKey('contract', $evidence);

        $target = $evidence['targets'][0];
        self::assertSame('resolved', $target['status']);
        self::assertSame('resolved_relations', $target['references']);
        self::assertSame('property:Fixture\Contract::$baseCommit', $target['symbol_id']);
        self::assertSame(['path' => 'src/Contract.php'], $target['declaration']);
        self::assertSame([['path' => 'tests/StoreTest.php', 'relation_counts' => ['property_access' => 2]]], $target['test_references']);
        self::assertSame([], $target['source_references']);
    }

    public function testAClassTargetCountsReferencesToItsMembersAndDuplicatesCollapse(): void
    {
        $this->writeFixture();
        $this->buildMap();

        $evidence = $this->enter('TARGET-CLASS', [
            '--target', 'Fixture\Contract',
            '--target', '\Fixture\Contract',
            '--target', 'Fixture\Store::create',
            '--target', 'Contract::$baseCommit',
        ])['payload']['references']['target_evidence'];

        self::assertCount(3, $evidence['targets'], 'A repeated target, with or without a leading backslash, is projected once.');
        [$class, $method, $shortProperty] = $evidence['targets'];
        self::assertSame('property:Fixture\Contract::$baseCommit', $shortProperty['symbol_id'], 'A unique short class name resolves like a method target does.');
        self::assertSame(['tests/StoreTest.php'], array_column($shortProperty['test_references'], 'path'));
        self::assertSame('src/Contract.php', $class['declaration']['path']);
        self::assertSame(['tests/StoreTest.php'], array_column($class['test_references'], 'path'));
        self::assertSame(['src/Store.php'], array_column($class['source_references'], 'path'));
        self::assertSame(['calls' => 1], $method['test_references'][0]['relation_counts']);
        self::assertSame('tests/StoreTest.php', $method['test_references'][0]['path']);
    }

    public function testAnApprovedContractMarksEachPathInsideOrOutsideItsScopeWithoutChangingIt(): void
    {
        $this->writeFixture();
        $this->buildMap();
        $contract = (new TaskContractStore($this->root))->create(
            taskId: 'TARGET-SCOPED',
            goal: 'Resolve the base before storing it',
            scope: ['src/Store.php', 'src/Contract.php'],
            nonGoals: [],
            validation: ['vendor/bin/phpunit'],
            plannedBy: 'test-planner',
        );
        $draft = $this->enter('TARGET-SCOPED', ['--target', 'Fixture\Contract::$baseCommit'])['payload']['references']['target_evidence'];
        self::assertArrayNotHasKey('in_contract_scope', $draft['targets'][0]['declaration'], 'A draft scope is still a proposal; paths are not marked against it.');

        (new TaskContractStore($this->root))->approve('TARGET-SCOPED', 'test-approver', 1);
        $contract = (new TaskContractStore($this->root))->load('TARGET-SCOPED');
        $contractBefore = file_get_contents($contract->path);

        $evidence = $this->enter('TARGET-SCOPED', ['--target', 'Fixture\Contract::$baseCommit'])['payload']['references']['target_evidence'];

        self::assertSame($contractBefore, file_get_contents($contract->path));
        self::assertSame(['revision' => 1, 'status' => $contract->status], $evidence['contract']);
        $target = $evidence['targets'][0];
        self::assertTrue($target['declaration']['in_contract_scope']);
        self::assertFalse($target['test_references'][0]['in_contract_scope'], 'An outside-scope test stays a lead; it is not added to scope.');
        self::assertSame(['src/Store.php', 'src/Contract.php'], (new TaskContractStore($this->root))->load('TARGET-SCOPED')->scope);
    }

    public function testMissingOrStaleMapAbstainsVisiblyAndLeavesTheNextActionUnchanged(): void
    {
        $this->writeFixture();
        $baseline = $this->enter('TARGET-MISSING');
        $missing = $this->enter('TARGET-MISSING', ['--target', 'Fixture\Store::create']);

        self::assertSame($baseline['payload']['next_action'], $missing['payload']['next_action']);
        self::assertSame('abstained', $missing['payload']['references']['target_evidence']['status']);
        self::assertSame('map_not_current', $missing['payload']['references']['target_evidence']['reason']);
        self::assertSame('none', $missing['payload']['references']['target_evidence']['authority']);

        $this->buildMap();
        file_put_contents($this->root . '/src/Store.php', self::STORE_SOURCE . "\n// changed after the map was built\n");
        $stale = $this->enter('TARGET-MISSING', ['--target', 'Fixture\Store::create'])['payload']['references']['target_evidence'];

        self::assertSame('abstained', $stale['status']);
        self::assertSame('map_not_current', $stale['reason']);
        self::assertSame('stale', $stale['map_state']);
        self::assertArrayNotHasKey('targets', $stale, 'A stale Map must not be presented as current evidence.');
    }

    public function testUnresolvedAmbiguousAndInvalidTargetsAbstainPerTarget(): void
    {
        $this->writeFixture();
        $this->writeFile('src/Other/Store.php', str_replace('namespace Fixture;', 'namespace Fixture\Other;', self::STORE_SOURCE));
        $this->buildMap();

        $evidence = $this->enter('TARGET-UNRESOLVED', [
            '--target', 'Fixture\Missing::nothing',
            '--target', 'Store::create',
            '--target', 'Fixture\Contract::$missing',
            '--target', 'Fixture\Store::create',
            '--target', 'Store',
        ])['payload']['references']['target_evidence'];

        self::assertSame('ready', $evidence['status']);
        $reasons = array_map(static fn (array $row): string => $row['reason'] ?? $row['status'], $evidence['targets']);
        self::assertSame(['target_unresolved', 'target_ambiguous', 'target_unresolved', 'resolved', 'target_ambiguous'], $reasons);

        $tooMany = $this->enter('TARGET-UNRESOLVED', array_merge(...array_map(
            static fn (int $index): array => ['--target', 'Fixture\Store::create' . $index],
            range(1, WorkflowTargetEvidenceProjector::MAX_TARGETS + 1),
        )))['payload']['references']['target_evidence'];
        self::assertSame('too_many_targets', $tooMany['reason']);
    }

    public function testReferencesAreBoundedPerRoleAndReportWhatWasOmitted(): void
    {
        $this->writeFixture();
        foreach (range(1, 10) as $index) {
            $this->writeFile(
                sprintf('tests/Reader%02dTest.php', $index),
                str_replace('StoreTest', sprintf('Reader%02dTest', $index), self::STORE_TEST_SOURCE),
            );
        }
        $this->buildMap();

        $target = $this->enter('TARGET-BOUNDED', ['--target', 'Fixture\Contract::$baseCommit'])['payload']['references']['target_evidence']['targets'][0];

        self::assertCount(8, $target['test_references']);
        self::assertSame(['test_files' => 3, 'source_files' => 0], $target['omitted']);
        $paths = array_column($target['test_references'], 'path');
        $sorted = $paths;
        sort($sorted);
        self::assertSame($sorted, $paths, 'Equal reference counts are ordered by path, deterministically.');
    }

    public function testTextOutputLabelsTheEvidenceAsStructuralAndNotScope(): void
    {
        $this->writeFixture();
        $this->buildMap();

        ob_start();
        (new HostFrontDoorCommand($this->root))->run('enter', ['TARGET-TEXT', '--target', 'Fixture\Contract::$baseCommit', '--target', 'Fixture\Nope']);
        $text = (string) ob_get_clean();

        self::assertStringContainsString("Target evidence (agent-map, ready, structural, not scope):\n", $text);
        self::assertStringContainsString("  Fixture\\Contract::\$baseCommit declared in src/Contract.php\n", $text);
        self::assertStringContainsString("    test tests/StoreTest.php (property_access 2)\n", $text);
        self::assertStringContainsString("  Fixture\\Nope: abstained (target_unresolved)\n", $text);
    }

    public function testATypeScriptTargetReportsMissingRelationsAsACapabilityGapNotAsNoReferences(): void
    {
        $compiler = getenv('AGENT_MAP_TYPESCRIPT_PATH');
        if (!is_string($compiler) || $compiler === '' || !is_dir($compiler)) {
            self::markTestSkipped('AGENT_MAP_TYPESCRIPT_PATH does not name a TypeScript compiler; agent-map cannot index TypeScript here.');
        }
        $this->writeFixture();
        $this->writeFile('web/matcher.ts', "export function matchGesture(input: string): boolean {\n    return input !== '';\n}\n");
        $this->writeFile('web/matcher.test.ts', "import { matchGesture } from './matcher';\n\nmatchGesture('x');\n");
        (new ProjectMapBuilder($this->root, [SourceLanguage::PHP, SourceLanguage::TYPESCRIPT]))->rebuild(
            MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map'),
            ['src/Contract.php', 'web/matcher.ts', 'web/matcher.test.ts'],
        );

        $target = $this->enter('TARGET-TS', ['--target', 'web/matcher.ts#matchGesture'])['payload']['references']['target_evidence']['targets'][0];

        self::assertSame('resolved', $target['status']);
        self::assertSame('web/matcher.ts', $target['declaration']['path']);
        self::assertSame('not_indexed_for_language', $target['references']);
        self::assertSame('typescript', $target['language']);
        self::assertArrayNotHasKey('test_references', $target, 'An unindexed relation kind must not look like an empty result.');
    }

    private function writeFixture(): void
    {
        $this->writeFile('src/Contract.php', self::CONTRACT_SOURCE);
        $this->writeFile('src/Store.php', self::STORE_SOURCE);
        $this->writeFile('tests/StoreTest.php', self::STORE_TEST_SOURCE);
    }

    private function writeFile(string $relative, string $contents): void
    {
        $path = $this->root . '/' . $relative;
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0o775, true) && !is_dir(dirname($path))) {
            throw new RuntimeException('Unable to create fixture directory: ' . dirname($path));
        }
        file_put_contents($path, $contents);
    }

    /** @SuppressWarnings("PHPMD.StaticAccess") */
    private function buildMap(): void
    {
        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        (new MapPreparationService())->prepare(new MapPreparationRequest(
            root: $this->root,
            indexPath: $artifacts->indexJson(),
            outputPath: $artifacts->indexJson(),
            format: 'json',
            paths: ['src', 'tests'],
            pathsProvided: true,
            scanPaths: [],
            scanPathsProvided: false,
            excludes: [],
            excludesProvided: false,
            backend: 'phpstan',
            phpStanConfig: null,
            phpStanMemoryLimit: null,
            artifacts: $artifacts,
        ));
    }

    /** @return array<string, string> */
    private function stateSnapshot(): array
    {
        $snapshot = [];
        $state = $this->root . '/.agent-loop';
        if (!is_dir($state)) {
            return $snapshot;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($state, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $snapshot[substr($file->getPathname(), strlen($this->root) + 1)] = (string) hash_file('sha256', $file->getPathname());
            }
        }
        ksort($snapshot);

        return $snapshot;
    }

    /**
     * @param list<string> $options
     * @return array{exit: int, payload: array<string, mixed>}
     */
    private function enter(string $taskId, array $options = []): array
    {
        ob_start();
        $exit = (new HostFrontDoorCommand($this->root))->run('enter', [$taskId, ...$options, '--format=json']);
        $stdout = ob_get_clean();
        if (!is_string($stdout)) {
            throw new RuntimeException('Unable to read enter command output.');
        }
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Enter command output is not a JSON object.');
        }

        return ['exit' => $exit, 'payload' => $payload];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
                continue;
            }
            unlink($file->getPathname());
        }
        rmdir($path);
    }
}
