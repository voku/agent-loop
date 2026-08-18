<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PDO;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowApproveCommand;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\IndexWriter;

/**
 * Ranked Search is derived Map evidence. Enter owns surfacing its degradation
 * because enter now owns deterministic post-approval preparation.
 *
 * @internal
 */
final class WorkflowApproveSearchIndexEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-enter-search-' . bin2hex(random_bytes(6));
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
        self::assertSame(0, $this->approve());
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testEnterReportsWhenRankedMapEvidenceIsUnavailable(): void
    {
        $result = $this->enter();

        self::assertSame(0, $result['exit']);
        self::assertTrue($result['payload']['mutation_ready'] ?? false);
        self::assertSame('enter.ranked_search_unavailable', $result['payload']['warnings'][0]['code'] ?? null);
        self::assertSame('agent-map', $result['payload']['warnings'][0]['owner'] ?? null);
        self::assertStringContainsString(
            '.agent-loop/map/search.sqlite',
            (string) ($result['payload']['warnings'][0]['message'] ?? ''),
        );
        self::assertStringContainsString(
            'map search-index build',
            (string) ($result['payload']['warnings'][0]['message'] ?? ''),
        );
        self::assertNotContains('--map-search-index', $result['recallArgs']);
    }

    public function testEnterStaysQuietWhenRankedMapEvidenceIsAvailable(): void
    {
        $this->writeSearchSnapshot('sha256:current');

        $result = $this->enter();

        self::assertSame(0, $result['exit']);
        self::assertSame([], $result['payload']['warnings'] ?? null);
        self::assertContains('--map-search-index', $result['recallArgs']);
    }

    public function testEnterReturnsBoundedContextWithoutClaimingConsumption(): void
    {
        $result = $this->enter();

        self::assertSame(0, $result['exit']);
        self::assertNotSame([], $result['payload']['context']['lines'] ?? []);
        self::assertSame('compiled', $result['payload']['manifest']['references']['recall']['state'] ?? null);
        self::assertFileDoesNotExist($this->root . '/.agent-loop/recall/ABC-123/brief_consumed.json');
    }

    private function approve(): int
    {
        ob_start();
        try {
            return (new WorkflowApproveCommand($this->root))->run(['ABC-123', '--by', 'lars']);
        } finally {
            ob_end_clean();
        }
    }

    /** @return array{exit: int, payload: array<string, mixed>, recallArgs: list<string>} */
    private function enter(): array
    {
        /** @var list<string> $recallArgs */
        $recallArgs = [];
        $runner = function (array $argv) use (&$recallArgs): int {
            $recallArgs = $argv;
            $this->writeRecallMeta();

            return 0;
        };

        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root, $runner))->run(
                'enter',
                ['ABC-123', '--format=json'],
            );
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new RuntimeException('Enter did not return a JSON object.');
        }

        return ['exit' => $exit, 'payload' => $payload, 'recallArgs' => $recallArgs];
    }

    private function writeRecallMeta(): void
    {
        $directory = RecallOutputRoot::resolve($this->root) . '/ABC-123';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Recall fixture directory.');
        }
        file_put_contents(
            $directory . '/meta.json',
            json_encode([
                'schema_version' => '1.0',
                'task_id' => 'ABC-123',
                'compilation_id' => 'ABC-123-001',
                'bundle_sha256' => str_repeat('a', 64),
                'selected_guidance' => [],
                'selected_constraints' => [],
                'output_hashes' => [],
            ], JSON_THROW_ON_ERROR),
        );
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
