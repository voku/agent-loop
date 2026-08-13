<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowContextCommand;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class WorkflowContextRankedMapExpansionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-ranked-map-context-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/tests', 0777, true);
        mkdir($this->root . '/.agent-loop/recall/MAP-69', 0777, true);
        mkdir($this->root . '/.agent-loop/map', 0777, true);

        file_put_contents($this->root . '/src/Leaf.php', <<<'PHP'
<?php
namespace Demo;

final class Leaf
{
    public function read(): void
    {
    }
}
PHP);
        file_put_contents($this->root . '/src/Orchestrator.php', <<<'PHP'
<?php
namespace Demo;

final class Orchestrator
{
    public function hydrate(Leaf $leaf): void
    {
        $leaf->read();
    }
}
PHP);
        file_put_contents($this->root . '/tests/LeafEntryPointsTest.php', <<<'PHP'
<?php
namespace Demo;

final class LeafEntryPointsTest
{
    public function testRead(): void
    {
        (new Leaf())->read();
    }
}
PHP);

        $leafMethod = new MethodEntry('read', 'public', 6, 9, reconciliationStatus: 'confirmed');
        $leaf = new SymbolEntry('class', 'Leaf', 'Demo\\Leaf', 4, 10, [$leafMethod], reconciliationStatus: 'confirmed');
        $orchestratorMethod = new MethodEntry('hydrate', 'public', 6, 10, reconciliationStatus: 'confirmed');
        $orchestrator = new SymbolEntry('class', 'Orchestrator', 'Demo\\Orchestrator', 4, 11, [$orchestratorMethod], reconciliationStatus: 'confirmed');
        $testMethod = new MethodEntry('testRead', 'public', 6, 9, reconciliationStatus: 'confirmed');
        $test = new SymbolEntry('class', 'LeafEntryPointsTest', 'Demo\\LeafEntryPointsTest', 4, 10, [$testMethod], reconciliationStatus: 'confirmed');

        $leafFile = $this->file('src/Leaf.php', [$leaf]);
        $orchestratorFile = $this->file('src/Orchestrator.php', [$orchestrator]);
        $testFile = $this->file('tests/LeafEntryPointsTest.php', [$test]);
        $relation = RelationEntry::create(
            sourceId: $orchestrator->methodId($orchestratorMethod),
            kind: 'calls',
            targetIds: [$leaf->methodId($leafMethod)],
            file: 'src/Orchestrator.php',
            lineStart: 8,
            lineEnd: 8,
            resolution: 'phpstan_resolved',
        );
        $testRelation = RelationEntry::create(
            sourceId: $test->methodId($testMethod),
            kind: 'calls',
            targetIds: [$leaf->methodId($leafMethod)],
            file: 'tests/LeafEntryPointsTest.php',
            lineStart: 8,
            lineEnd: 8,
            resolution: 'phpstan_resolved',
        );
        (new IndexWriter())->write(new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'phpstan+simple-parser',
            files: [$leafFile, $orchestratorFile, $testFile],
            relations: [$relation, $testRelation],
            fingerprint: new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:fixture-map'),
        ), $this->root . '/.agent-loop/map/php-symbols.json');

        $contracts = new TaskContractStore($this->root);
        $contracts->create(
            'MAP-69',
            'Follow the ranked lead without promoting it into approved scope.',
            ['src/Leaf.php'],
            [],
            ['composer test'],
            'dogfood',
        );
        $contracts->approve('MAP-69', 'dogfood');

        file_put_contents($this->root . '/.agent-loop/recall/MAP-69/meta.json', json_encode([
            'schema_version' => '1.0',
            'task_id' => 'MAP-69',
            'selected_guidance' => [],
            'selected_constraints' => [],
        ], JSON_THROW_ON_ERROR));
        $this->writeCandidateFacts('sha256:fixture-map');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRankedMethodLeadExpandsThroughExistingMapRelationsWithoutChangingApprovedScope(): void
    {
        $context = (new WorkflowContextCommand($this->root))->build('MAP-69', null, 120, 12000);
        $rendered = implode("\n", $context['lines']);

        self::assertStringContainsString('Candidate map leads (ranked, unverified):', $rendered);
        self::assertStringContainsString('rank 1 Demo\\Leaf::read', $rendered);
        self::assertStringContainsString('Candidate structural context (unverified):', $rendered);
        self::assertStringContainsString('src/Orchestrator.php:6-10 [change_candidate]', $rendered);
        self::assertStringContainsString('direct caller that may need adaptation', $rendered);
        self::assertStringContainsString('Demo\\Leaf — src/Leaf.php:4', $rendered);
    }

    public function testRankedTestLeadExposesDirectProjectCalleeWithoutBecomingAnEditSeed(): void
    {
        $this->writeRankedTestCandidateFacts('sha256:fixture-map');

        $context = (new WorkflowContextCommand($this->root))->build('MAP-69', null, 120, 12000);
        $rendered = implode("\n", $context['lines']);

        self::assertStringContainsString('rank 1 Demo\\LeafEntryPointsTest::testRead', $rendered);
        self::assertStringContainsString(
            'rank 1 test evidence calls Demo\\Leaf::read — src/Leaf.php:6-9',
            $rendered,
        );
        self::assertStringNotContainsString('rank 1 seed Demo\\LeafEntryPointsTest::testRead', $rendered);
    }

    public function testCandidateExpansionFailsClosedWhenRecallAndCurrentMapSnapshotsDiffer(): void
    {
        $this->writeCandidateFacts('sha256:older-map');

        $context = (new WorkflowContextCommand($this->root))->build('MAP-69', null, 120, 12000);
        $rendered = implode("\n", $context['lines']);

        self::assertStringContainsString('rank 1 Demo\\Leaf::read', $rendered);
        self::assertStringNotContainsString('src/Orchestrator.php:6-10 [change_candidate]', $rendered);
        self::assertContains(
            'agent-map candidate expansion: map snapshot mismatch (recall sha256:older-map, current sha256:fixture-map)',
            $context['skipped'],
        );
        self::assertStringContainsString('Demo\\Leaf — src/Leaf.php:4', $rendered);
    }

    /** @param list<SymbolEntry> $symbols */
    private function file(string $path, array $symbols): FileEntry
    {
        $hash = hash_file('sha256', $this->root . '/' . $path);
        self::assertIsString($hash);

        return new FileEntry($path, 'sha256:' . $hash, 'Demo', $symbols, 'analysed');
    }

    private function writeCandidateFacts(string $mapSnapshot): void
    {
        file_put_contents($this->root . '/.agent-loop/recall/MAP-69/facts.json', json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => 'bundle-map-69',
            'facts' => [[
                'id' => 'map.search.candidates',
                'type' => 'navigation_candidates',
                'authority' => 'derived_navigation',
                'source_ref' => '.agent-loop/map/search.sqlite',
                'scope' => ['src/Leaf.php'],
                'payload' => [
                    'status' => 'ranked',
                    'query' => 'relative type formatting regression',
                    'limit' => 8,
                    'map_snapshot' => $mapSnapshot,
                    'search_index_snapshot' => $mapSnapshot,
                    'results' => [[
                        'chunk_id' => 'method:Demo\\Leaf::read#method_body',
                        'symbol_id' => 'method:Demo\\Leaf::read',
                        'file_path' => 'src/Leaf.php',
                        'start_line' => 6,
                        'end_line' => 9,
                        'rrf_score' => 0.016393,
                        'channel_ranks' => ['structural' => null, 'lexical' => 1, 'semantic' => null],
                        'content_sha256' => 'sha256:fixture',
                        'reasons' => ['lexical_rank:1'],
                    ]],
                ],
                'conflict_key' => null,
            ]],
        ], JSON_THROW_ON_ERROR));
    }

    private function writeRankedTestCandidateFacts(string $mapSnapshot): void
    {
        file_put_contents($this->root . '/.agent-loop/recall/MAP-69/facts.json', json_encode([
            'schema_version' => '1.0',
            'bundle_sha256' => 'bundle-map-69-test-evidence',
            'facts' => [
                [
                    'id' => 'map.scope.leaf',
                    'type' => 'navigation',
                    'authority' => 'derived_navigation',
                    'source_ref' => '.agent-loop/map/php-symbols.json',
                    'scope' => ['src/Leaf.php'],
                    'payload' => [
                        'path' => 'src/Leaf.php',
                        'symbols' => [[
                            'fqn' => 'Demo\\Leaf',
                            'line_start' => 4,
                        ]],
                    ],
                    'conflict_key' => null,
                ],
                [
                    'id' => 'map.search.candidates',
                    'type' => 'navigation_candidates',
                    'authority' => 'derived_navigation',
                    'source_ref' => '.agent-loop/map/search.sqlite',
                    'scope' => ['src/Leaf.php'],
                    'payload' => [
                        'status' => 'ranked',
                        'query' => 'existing read entry point regression coverage',
                        'limit' => 8,
                        'map_snapshot' => $mapSnapshot,
                        'search_index_snapshot' => $mapSnapshot,
                        'results' => [[
                            'chunk_id' => 'method:Demo\\LeafEntryPointsTest::testRead#method_body',
                            'symbol_id' => 'method:Demo\\LeafEntryPointsTest::testRead',
                            'file_path' => 'tests/LeafEntryPointsTest.php',
                            'start_line' => 6,
                            'end_line' => 9,
                            'rrf_score' => 0.016393,
                            'channel_ranks' => ['structural' => null, 'lexical' => 1, 'semantic' => null],
                            'content_sha256' => 'sha256:fixture-test',
                            'reasons' => ['lexical_rank:1'],
                        ]],
                    ],
                    'conflict_key' => null,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
