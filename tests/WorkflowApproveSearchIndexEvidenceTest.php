<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\IndexWriter;

/**
 * A governed context that quietly lost its ranked evidence looks correct.
 *
 * Approve compiles Recall with `--map-search-index` only when agent-map proves
 * the derived Search index belongs to the current map snapshot. Missing or
 * stale ranked evidence remains a visible degradation instead of being inferred
 * from whether a SQLite file happens to exist.
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
        (new IndexWriter())->write(
            new AgentMapIndex(
                schemaVersion: '2.0',
                root: $this->root,
                backend: 'test',
                files: [],
                fingerprint: new AnalysisFingerprint(
                    '2.2.0',
                    'sha256:config',
                    'sha256:lock',
                    'sha256:current',
                ),
            ),
            $this->root . '/.agent-loop/map/php-symbols.json',
        );

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
        self::assertStringContainsString('[WARN] workflow approve: agent-map Search is missing at', $result['output']);
        self::assertStringContainsString('.agent-loop/map/search.sqlite', $result['output']);
        self::assertStringContainsString('map search-index build', $result['output']);
        self::assertNotContains('--map-search-index', $result['recallArgs']);
    }

    public function testApproveStaysQuietWhenRankedMapEvidenceIsAvailable(): void
    {
        $this->writeSearchSnapshot('sha256:current');

        $result = $this->approve();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('[WARN] workflow approve:', $result['output']);
        self::assertContains('--map-search-index', $result['recallArgs']);
    }

    public function testApprovePrintsDeterministicRecallHandoffWithoutClaimingConsumption(): void
    {
        $result = $this->approve();

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString(
            '[NEXT] vendor/bin/agent-loop workflow context ABC-123 --max-lines 120 --max-bytes 12000',
            $result['output'],
        );
        self::assertStringContainsString(
            '[NEXT] Read .agent-loop/recall/ABC-123/system.md before planning or modifying code.',
            $result['output'],
        );
        self::assertStringNotContainsString('consumed=true', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.agent-loop/recall/ABC-123/brief_consumed.json');
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

    private function writeSearchSnapshot(string $snapshot): void
    {
        $pdo = new PDO(
            'sqlite:' . $this->root . '/.agent-loop/map/search.sqlite',
            null,
            null,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $pdo->exec('CREATE TABLE search_meta (key TEXT PRIMARY KEY, value TEXT NOT NULL)');
        $statement = $pdo->prepare('INSERT INTO search_meta (key, value) VALUES (:key, :value)');
        $statement->execute(['key' => 'map_snapshot', 'value' => $snapshot]);
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
