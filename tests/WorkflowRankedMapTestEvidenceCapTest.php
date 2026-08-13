<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowContextBudget;
use voku\AgentLoop\Workflow\WorkflowRankedMapContextExpander;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\AnalysisFingerprint;
use voku\AgentMap\Index\FileEntry;
use voku\AgentMap\Index\IndexWriter;
use voku\AgentMap\Index\MethodEntry;
use voku\AgentMap\Index\RelationEntry;
use voku\AgentMap\Index\SymbolEntry;

final class WorkflowRankedMapTestEvidenceCapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-ranked-test-cap-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/src', 0777, true);
        mkdir($this->root . '/tests', 0777, true);
        mkdir($this->root . '/.agent-loop/map', 0777, true);

        file_put_contents($this->root . '/src/Noise.php', <<<'PHP'
<?php
namespace Demo;

final class Noise
{
    public function one(): void {}
    public function two(): void {}
    public function three(): void {}
    public function four(): void {}
}
PHP);
        file_put_contents($this->root . '/src/Leaf.php', <<<'PHP'
<?php
namespace Demo;

final class Leaf
{
    public function read(): void {}
}
PHP);
        file_put_contents($this->root . '/tests/LeafTest.php', <<<'PHP'
<?php
namespace Demo;

final class LeafTest
{
    public function testRead(): void
    {
        (new Leaf())->read();
    }
}
PHP);

        $noiseMethods = [
            new MethodEntry('one', 'public', 6, 6, reconciliationStatus: 'confirmed'),
            new MethodEntry('two', 'public', 7, 7, reconciliationStatus: 'confirmed'),
            new MethodEntry('three', 'public', 8, 8, reconciliationStatus: 'confirmed'),
            new MethodEntry('four', 'public', 9, 9, reconciliationStatus: 'confirmed'),
        ];
        $noise = new SymbolEntry('class', 'Noise', 'Demo\\Noise', 4, 10, $noiseMethods, reconciliationStatus: 'confirmed');
        $leafMethod = new MethodEntry('read', 'public', 6, 6, reconciliationStatus: 'confirmed');
        $leaf = new SymbolEntry('class', 'Leaf', 'Demo\\Leaf', 4, 7, [$leafMethod], reconciliationStatus: 'confirmed');
        $testMethod = new MethodEntry('testRead', 'public', 6, 9, reconciliationStatus: 'confirmed');
        $test = new SymbolEntry('class', 'LeafTest', 'Demo\\LeafTest', 4, 10, [$testMethod], reconciliationStatus: 'confirmed');

        $productionFile = $this->file('src/Noise.php', [$noise]);
        $leafFile = $this->file('src/Leaf.php', [$leaf]);
        $testFile = $this->file('tests/LeafTest.php', [$test]);
        $relation = RelationEntry::create(
            sourceId: $test->methodId($testMethod),
            kind: 'calls',
            targetIds: [$leaf->methodId($leafMethod)],
            file: 'tests/LeafTest.php',
            lineStart: 8,
            lineEnd: 8,
            resolution: 'phpstan_resolved',
        );

        (new IndexWriter())->write(new AgentMapIndex(
            schemaVersion: '2.0',
            root: $this->root,
            backend: 'phpstan+simple-parser',
            files: [$productionFile, $leafFile, $testFile],
            relations: [$relation],
            fingerprint: new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:fixture-map'),
        ), $this->root . '/.agent-loop/map/php-symbols.json');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTestEvidenceStillExpandsAfterProductionSeedCap(): void
    {
        $budget = new WorkflowContextBudget(120, 12000);
        (new WorkflowRankedMapContextExpander($this->root))->add($budget, [
            'payload' => [
                'status' => 'ranked',
                'map_snapshot' => 'sha256:fixture-map',
                'results' => [
                    $this->candidate('method:Demo\\Noise::one', 'src/Noise.php', 6),
                    $this->candidate('method:Demo\\Noise::two', 'src/Noise.php', 7),
                    $this->candidate('method:Demo\\Noise::three', 'src/Noise.php', 8),
                    $this->candidate('method:Demo\\Noise::four', 'src/Noise.php', 9),
                    $this->candidate('method:Demo\\LeafTest::testRead', 'tests/LeafTest.php', 6),
                ],
            ],
        ]);
        $budget->finish();
        $rendered = implode("\n", $budget->lines());

        self::assertStringContainsString('rank 1 seed Demo\\Noise::one', $rendered);
        self::assertStringContainsString('rank 3 seed Demo\\Noise::three', $rendered);
        self::assertStringNotContainsString('rank 4 seed Demo\\Noise::four', $rendered);
        self::assertStringContainsString('rank 5 test evidence calls Demo\\Leaf::read', $rendered);
        self::assertStringNotContainsString('rank 5 seed Demo\\LeafTest::testRead', $rendered);
    }

    /** @param list<SymbolEntry> $symbols */
    private function file(string $path, array $symbols): FileEntry
    {
        $hash = hash_file('sha256', $this->root . '/' . $path);
        self::assertIsString($hash);

        return new FileEntry($path, 'sha256:' . $hash, 'Demo', $symbols, 'analysed');
    }

    /** @return array<string, mixed> */
    private function candidate(string $symbolId, string $path, int $line): array
    {
        return [
            'symbol_id' => $symbolId,
            'file_path' => $path,
            'start_line' => $line,
            'end_line' => $line,
        ];
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
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->removeDirectory($child) : unlink($child);
        }
        rmdir($path);
    }
}
