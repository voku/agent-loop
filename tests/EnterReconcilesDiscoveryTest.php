<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;

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

    public function testRankedSearchStaysOptionalRatherThanBecomingAPreparationGate(): void
    {
        $this->approvedPhpContract('MAP-2');
        $this->enter('MAP-2');

        // Building the ranked index here would turn an optional owner capability
        // into a mandatory preparation cost for every governed task.
        self::assertFileDoesNotExist($this->root . '/.agent-loop/map/search.sqlite');
    }

    public function testRepeatedEnterDoesNotRebuildAReadyMap(): void
    {
        $this->approvedPhpContract('MAP-3');
        $this->enter('MAP-3');
        $first = (string) file_get_contents($this->mapIndex());

        $this->enter('MAP-3');

        self::assertSame($first, (string) file_get_contents($this->mapIndex()), 'repeated enter must stay idempotent');
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

    private function enter(string $taskId): void
    {
        $dispatcher = new Dispatcher($this->root);
        $runner = static function (array $rest) use ($dispatcher): int {
            /** @var list<string> $argv */
            $argv = ['agent-loop', 'recall', ...array_values($rest)];

            return $dispatcher->run($argv);
        };

        ob_start();
        try {
            (new HostFrontDoorCommand($this->root, $runner))->run('enter', [$taskId, '--format=json']);
        } finally {
            ob_end_clean();
        }
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
