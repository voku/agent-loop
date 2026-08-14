<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\ProjectLayout;

/**
 * The derived search index is what turns retrieval into ranked Recall evidence.
 *
 * `WorkflowApproveCommand` passes `--map-search-index` to the recall compiler
 * only when a file exists at `ProjectLayout::mapSearchIndex()`. Nothing wrote
 * that path: `agent-loop map search-index build` fell through to agent-map's
 * own `.agent-map/search.sqlite` default, so a consumer that followed the
 * documented setup built the index somewhere agent-loop never reads. Recall
 * then compiled with no ranked facts and the governed context carried only the
 * symbols already named in the approved scope — measured on a real
 * Simple-PHP-Code-Parser replay as 17 context lines instead of 55, with the
 * decisive `PhpCodeParser::getAstFromString()` absent.
 *
 * @internal
 */
final class MapSearchIndexOwnershipTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-map-search-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/src/Greeter.php',
            "<?php\n\ndeclare(strict_types=1);\n\nfinal class Greeter\n{\n    public function greet(string \$name): string\n    {\n        return 'Hello ' . \$name;\n    }\n}\n",
        );
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testSearchIndexIsBuiltWhereTheWorkflowReadsIt(): void
    {
        $layout = new ProjectLayout($this->root);

        $build = $this->dispatch(['agent-loop', 'map', 'build', '--paths=src']);
        self::assertSame(0, $build['exit'], $build['output']);

        $indexed = $this->dispatch(['agent-loop', 'map', 'search-index', 'build']);
        if ($indexed['exit'] !== 0) {
            self::markTestSkipped('agent-map search index is unavailable in this PHP build: ' . $indexed['output']);
        }

        self::assertFileExists($layout->mapSearchIndex());
        self::assertFileDoesNotExist(
            $this->root . '/.agent-map/search.sqlite',
            'The search index was written to the retired agent-map default instead of the owned state root.',
        );
    }

    public function testAnExplicitDatabaseStillWins(): void
    {
        $build = $this->dispatch(['agent-loop', 'map', 'build', '--paths=src']);
        self::assertSame(0, $build['exit'], $build['output']);

        $custom = $this->root . '/var/custom-search.sqlite';
        mkdir(dirname($custom), 0o775, true);
        $indexed = $this->dispatch(['agent-loop', 'map', 'search-index', 'build', '--database=' . $custom]);
        if ($indexed['exit'] !== 0) {
            self::markTestSkipped('agent-map search index is unavailable in this PHP build: ' . $indexed['output']);
        }

        self::assertFileExists($custom);
        self::assertFileDoesNotExist((new ProjectLayout($this->root))->mapSearchIndex());
    }

    public function testTheMapNamespaceAdvertisesTheSearchIndexCommand(): void
    {
        $usage = $this->dispatch(['agent-loop', 'help'])['output'];

        self::assertStringContainsString('search-index', $usage);
    }

    /**
     * @param list<string> $argv
     * @return array{exit: int, output: string}
     */
    private function dispatch(array $argv): array
    {
        ob_start();
        $exit = (new Dispatcher($this->root))->run($argv);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function rm(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rm($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
