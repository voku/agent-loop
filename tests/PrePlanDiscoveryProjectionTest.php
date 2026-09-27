<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentMap\Build\StructuralOnlySemanticAnalyzer;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Search\ChunkExtractor;
use voku\AgentMap\Search\ChunkPolicy;
use voku\AgentMap\Search\SearchIndexStore;

final class PrePlanDiscoveryProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (!SearchIndexStore::supportsFts5()) {
            self::markTestSkipped('SQLite FTS5 is required for pre-PLAN ranked discovery coverage.');
        }

        $this->root = sys_get_temp_dir() . '/agent-loop-pre-plan-discovery-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/src', 0o775, true) && !is_dir($this->root . '/src')) {
            throw new RuntimeException('Unable to create test source directory.');
        }
        if (!mkdir($this->root . '/.agent-loop/todo/cards', 0o775, true) && !is_dir($this->root . '/.agent-loop/todo/cards')) {
            throw new RuntimeException('Unable to create test board directory.');
        }

        file_put_contents(
            $this->root . '/.agent-loop/todo/kanban.config.json',
            json_encode(['projectPrefix' => 'ABC'], JSON_THROW_ON_ERROR),
        );
        file_put_contents(
            $this->root . '/.agent-loop/todo/cards/ABC-637.md',
            <<<'CARD'
# ABC-637: Fix ClaudeHookTargetCatalog repository hook scope

- **Ticket:** ABC-637
- **Lane:** READY
- **Status:** Selected
- **Summary:** Keep project-owned Claude hooks inside the repository.
- **Next:** Inspect ClaudeHookTargetCatalog::projectSettings and remove the CLAUDE_CONFIG_DIR user-scope fallback without changing unrelated hooks.
CARD
            . "\n",
        );
        file_put_contents(
            $this->root . '/src/ClaudeHookTargetCatalog.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Consumer;

final class ClaudeHookTargetCatalog
{
    public function projectSettings(string $root): string
    {
        $config = getenv('CLAUDE_CONFIG_DIR');

        return is_string($config) && $config !== '' ? $config . '/settings.json' : $root . '/.claude/settings.json';
    }
}
PHP
            . "\n",
        );
        file_put_contents(
            $this->root . '/src/InitSyncHooksCommand.php',
            <<<'PHP'
<?php

declare(strict_types=1);

namespace Consumer;

final class InitSyncHooksCommand
{
    public function settingsPath(string $root): string
    {
        return (new ClaudeHookTargetCatalog())->projectSettings($root);
    }
}
PHP
            . "\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUnplannedEnterProjectsFreshReadOnlyEvidenceWithoutCreatingAuthority(): void
    {
        $artifacts = $this->buildDiscovery();
        $mapHash = hash_file('sha256', $artifacts->indexJson());
        $searchHash = hash_file('sha256', $artifacts->searchDatabase());
        $directoryEntries = $this->directoryEntries(dirname($artifacts->searchDatabase()));

        $result = $this->enter('ABC-637');

        self::assertSame(1, $result['exit'], $result['stderr']);
        $payload = $result['payload'];
        self::assertSame('command_template', $payload['next_action_kind']);
        self::assertStringContainsString('workflow plan ABC-637', $payload['next_action']);
        self::assertSame('ranked_unverified', $payload['pre_plan_discovery']['evidence_state']);
        self::assertSame(
            $payload['pre_plan_discovery']['map_snapshot'],
            $payload['pre_plan_discovery']['search_snapshot'],
        );
        self::assertContains(
            'src/ClaudeHookTargetCatalog.php',
            array_column($payload['pre_plan_discovery']['candidates'], 'file'),
        );
        self::assertNotSame([], $payload['pre_plan_discovery']['structural_context']);
        self::assertSame(
            'Consumer\\ClaudeHookTargetCatalog::projectSettings',
            $payload['pre_plan_discovery']['structural_context'][0]['target'],
        );

        self::assertFileDoesNotExist(
            $this->root . '/.agent-loop/contracts/ABC-637/contract.json',
            'enter must not turn discovery into an automatic PLAN.',
        );
        self::assertFileDoesNotExist(
            $this->root . '/.agent-loop/contracts/ABC-637/approval.json',
            'enter must not turn discovery into approval.',
        );
        self::assertSame($mapHash, hash_file('sha256', $artifacts->indexJson()));
        self::assertSame($searchHash, hash_file('sha256', $artifacts->searchDatabase()));
        self::assertSame($directoryEntries, $this->directoryEntries(dirname($artifacts->searchDatabase())));
    }

    public function testStaleMapDoesNotMasqueradeAsPrePlanEvidence(): void
    {
        $this->buildDiscovery();
        file_put_contents(
            $this->root . '/src/ClaudeHookTargetCatalog.php',
            str_replace(
                "return is_string(\$config)",
                "return /* changed after map build */ is_string(\$config)",
                (string) file_get_contents($this->root . '/src/ClaudeHookTargetCatalog.php'),
            ),
        );

        $result = $this->enter('ABC-637');

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame('command_template', $result['payload']['next_action_kind']);
        self::assertArrayNotHasKey('pre_plan_discovery', $result['payload']);
    }

    public function testMissingSearchPreservesTheExistingPlanRouteWithoutPreparation(): void
    {
        $artifacts = $this->buildMap();

        $result = $this->enter('ABC-637');

        self::assertSame(1, $result['exit'], $result['stderr']);
        self::assertSame('command_template', $result['payload']['next_action_kind']);
        self::assertArrayNotHasKey('pre_plan_discovery', $result['payload']);
        self::assertFileDoesNotExist($artifacts->searchDatabase());
    }

    private function buildDiscovery(): MapArtifactPaths
    {
        $artifacts = $this->buildMap();
        $map = (new \voku\AgentMap\Index\IndexReader())->read($artifacts->indexJson());
        $search = new SearchIndexStore($artifacts->searchDatabase());
        $search->replaceChunks((new ChunkExtractor())->extract($map));
        $search->setMeta('map_snapshot', $map->fingerprint?->sourceDigest ?? 'sha256:none');
        $search->setMeta('chunk_policy_version', (string) ChunkPolicy::VERSION);

        return $artifacts;
    }

    private function buildMap(): MapArtifactPaths
    {
        $artifacts = MapArtifactPaths::forProject($this->root, $this->root . '/.agent-loop/map');
        $map = (new AgentMapBuilder(
            semanticAnalyzer: new StructuralOnlySemanticAnalyzer(),
            artifacts: $artifacts,
        ))->build($this->root, ['src'], [], null, null, null);
        (new IndexWriter())->write($map, $artifacts->indexJson());

        return $artifacts;
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string, payload: array<string, mixed>}
     */
    private function enter(string $taskId): array
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('enter', [$taskId, '--format=json']);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($payload);

        return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => '', 'payload' => $payload];
    }

    /** @return list<string> */
    private function directoryEntries(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);

        return $entries;
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
