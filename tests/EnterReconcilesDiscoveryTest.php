<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;

/**
 * Deterministic Map preparation belongs behind `enter`, not in host prose.
 *
 * The installed-consumer measurement in #242 showed a "zero manual preparation"
 * result that was true only inside the measured window: the consumer had
 * already run agent-map build and search-index build beforehand. Map readiness
 * needs no human decision, so requiring the host to produce it first was
 * choreography.
 */
final class EnterReconcilesDiscoveryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-enter-discovery-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nnamespace Consumer;\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return 'Hello ' . \$name;\n    }\n}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testEnterBuildsTheMapItselfWithoutAnyHostPreparation(): void
    {
        $this->approvedPhpContract('MAP-1');
        self::assertFileDoesNotExist($this->mapIndex());

        $this->enter('MAP-1');

        self::assertFileExists($this->mapIndex(), 'enter must reconcile Map discovery itself');
    }

    public function testAutomaticMapContainsOnlyTheApprovedExistingPhpScope(): void
    {
        file_put_contents($this->root . '/src/Unrelated.php', "<?php\n\nfinal class Unrelated {}\n");
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/map/Generated.php', "<?php\n\nfinal class Generated {}\n");
        $this->approvedPhpContract('MAP-SCOPE');

        $this->enter('MAP-SCOPE');

        $map = (new IndexReader())->read($this->mapIndex());
        self::assertNotNull($map->file('src/Greeter.php'));
        self::assertNull($map->file('src/Unrelated.php'));
        self::assertNull($map->file('.agent-loop/map/Generated.php'));
        self::assertSame('simple-php-code-parser+structural-only', $map->backend);
    }

    public function testRankedSearchStaysOptionalRatherThanBecomingAPreparationGate(): void
    {
        $this->approvedPhpContract('MAP-2');
        $this->enter('MAP-2');

        // Building the ranked index here would turn an optional owner capability
        // into a mandatory preparation cost for every governed task.
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/search.sqlite');
    }

    public function testRepeatedEnterDoesNotRebuildAReadyBroaderMap(): void
    {
        file_put_contents($this->root . '/src/Unrelated.php', "<?php\n\nfinal class Unrelated {}\n");
        $this->approvedPhpContract('MAP-3');
        $this->buildStructuralMap(['src']);
        $beforeEnter = (string) file_get_contents($this->mapIndex());

        $this->enter('MAP-3');
        $first = (string) file_get_contents($this->mapIndex());
        $this->enter('MAP-3');

        self::assertSame($beforeEnter, $first, 'enter must preserve a ready broader Map');
        self::assertSame($first, (string) file_get_contents($this->mapIndex()), 'repeated enter must stay idempotent');
    }

    public function testStaleDifferentBackendMapIsReplacedWithoutCarryingUnrelatedEntries(): void
    {
        file_put_contents($this->root . '/src/Unrelated.php', "<?php\n\nfinal class Unrelated {}\n");
        $this->approvedPhpContract('MAP-STALE');
        $this->buildStructuralMap(['src']);
        $previous = (string) file_get_contents($this->mapIndex());
        file_put_contents(
            $this->mapIndex(),
            str_replace(
                'simple-php-code-parser+structural-only',
                'simple-php-code-parser+phpstan',
                $previous,
            ),
        );
        file_put_contents($this->root . '/src/Greeter.php', "<?php\n\nfinal class Greeter {}\n");

        $this->enter('MAP-STALE');

        $map = (new IndexReader())->read($this->mapIndex());
        self::assertNotNull($map->file('src/Greeter.php'));
        self::assertNull($map->file('src/Unrelated.php'));
        self::assertSame('simple-php-code-parser+structural-only', $map->backend);
    }

    public function testDocumentationOnlyWorkBuildsNoMapAtAll(): void
    {
        file_put_contents($this->root . '/README.md', "# Demo\n");
        $contracts = new TaskContractStore($this->root);
        $contracts->create('DOC-9', 'Update docs.', ['README.md'], [], ['php -r "exit(0);"'], 'planner');
        $contracts->approve('DOC-9', 'approver');

        $this->enter('DOC-9');

        self::assertFileDoesNotExist($this->mapIndex(), 'non-PHP work must not pay for Map discovery');
    }

    /**
     * Preparation used to write a scope-sized build straight over the shared
     * index. A repository map of thousands of files was replaced by the handful
     * this one Contract touched, and every later consumer - map queries, the
     * plan family, Recall map evidence - silently saw only those.
     */
    public function testPreparingOneContractDoesNotDropTheRestOfAnExistingMap(): void
    {
        file_put_contents($this->root . '/src/Unrelated.php', "<?php\n\nfinal class Unrelated {}\n");
        $this->approvedPhpContract('MAP-KEEP');

        // A pre-existing repository-wide index, as a real project has.
        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        (new IndexWriter())->write(
            (new AgentMapBuilder(artifacts: $artifacts))->build($this->root, ['src'], []),
            $this->mapIndex(),
        );
        $before = (new IndexReader())->read($this->mapIndex());
        self::assertNotNull($before->file('src/Unrelated.php'));

        $this->enter('MAP-KEEP');

        $after = (new IndexReader())->read($this->mapIndex());
        self::assertNotNull($after->file('src/Greeter.php'), 'the Contract scope must be present');
        self::assertNotNull(
            $after->file('src/Unrelated.php'),
            'preparation must patch the Contract scope into the existing index, not replace it',
        );
    }

    private function mapIndex(): string
    {
        return $this->root . '/.agent-loop/map/php-symbols.json';
    }

    private function approvedPhpContract(string $taskId): void
    {
        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            $taskId,
            'Change existing PHP behaviour.',
            ['src/Greeter.php'],
            [],
            ['php -l src/Greeter.php'],
            'planner',
        );
        $contracts->approve($taskId, 'approver');
    }

    /** @param list<string> $paths */
    private function buildStructuralMap(array $paths): void
    {
        $artifacts = MapArtifactPaths::forProject($this->root, '.agent-loop/map');
        $map = (new AgentMapBuilder(
            semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
            artifacts: $artifacts,
        ))->build($this->root, $paths, [], null, null, null);
        (new IndexWriter())->write($map, $artifacts->indexJson());
    }

    /**
     * Enter, asserting it actually succeeded.
     *
     * Discarding the exit code here would let the Map assertions pass while
     * `enter` built the snapshot and then failed during Recall preparation:
     * the file would exist and the test would still be green.
     *
     * @return array<string, mixed>
     */
    private function enter(string $taskId): array
    {
        $dispatcher = new Dispatcher($this->root);
        $runner = static function (array $rest) use ($dispatcher): int {
            /** @var list<string> $argv */
            $argv = ['agent-loop', 'recall', ...array_values($rest)];

            return $dispatcher->run($argv);
        };

        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root, $runner))->run('enter', [$taskId, '--format=json']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, 'enter must reach mutation readiness, not merely produce a Map');
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);
        self::assertTrue($payload['mutation_ready'] ?? null, 'enter reported no mutation readiness');

        return $payload;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
