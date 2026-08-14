<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;

/**
 * A governed context that quietly lost its ranked evidence looks correct.
 *
 * Approve compiles Recall with `--map-search-index` only when the derived index
 * exists. When it does not, Recall still succeeds and still writes every
 * artifact — it simply carries no ranked facts, so the context reaching the
 * execution agent holds only the symbols the approved scope already named. That
 * degradation was invisible: approve printed the same success lines either way.
 *
 * @internal
 */
final class WorkflowApproveSearchIndexEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-approve-search-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/map/php-symbols.json', '{}');

        (new TaskContractStore($this->root))->create(
            'ABC-123',
            'Keep scope reviewable.',
            ['src/Foo.php'],
            [],
            ['vendor/bin/phpunit'],
            'lars',
        );
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testApproveReportsWhenRankedMapEvidenceIsUnavailable(): void
    {
        $result = $this->approve();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[WARN] workflow approve: no search index at', $result['output']);
        self::assertStringContainsString('.agent-loop/map/search.sqlite', $result['output']);
        self::assertStringContainsString('map search-index build', $result['output']);
        self::assertNotContains('--map-search-index', $result['recallArgs']);
    }

    public function testApproveStaysQuietWhenRankedMapEvidenceIsAvailable(): void
    {
        file_put_contents($this->root . '/.agent-loop/map/search.sqlite', '');

        $result = $this->approve();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('no search index at', $result['output']);
        self::assertContains('--map-search-index', $result['recallArgs']);
    }

    /** @return array{exit: int, output: string, recallArgs: list<string>} */
    private function approve(): array
    {
        /** @var list<string> $recallArgs */
        $recallArgs = [];
        $command = new WorkflowApproveCommand(
            $this->root,
            static function (array $argv) use (&$recallArgs): int {
                $recallArgs = $argv;

                return 0;
            },
        );

        ob_start();
        $exit = $command->run(['ABC-123', '--by', 'lars']);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output, 'recallArgs' => $recallArgs];
    }

    private function rm(string $path): void
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
