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

        file_put_contents($this->root . '/src/Fixture.php', <<<'PHP'
<?php
namespace Demo;

final class Noise
{
    public function one(): void {}
    public function two(): void {}
    public function three(): void {}
    public function four(): void {}
}

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
        $leafMethod = new MethodEntry('read', 'public', 14, 14, reconciliationStatus: 'confirmed');
        $leaf = new SymbolEntry('class', 'Leaf', 'Demo\\Leaf', 12, 15, [$leafMethod], reconciliationStatus: 'confirmed');
        $testMethod = new MethodEntry('testRead', 'public', 6, 9, reconciliationStatus: 'confirmed');
        $test = new SymbolEntry('class', 'LeafTest', 'Demo\\LeafTest', 4, 10, [$testMethod], reconciliationStatus: 'confirmed');

        $fixtureFile = $this->file('src/Fixture.php', [$noise, $leaf]);
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
            files: [$fixtureFile, $testFile],
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
                    $this->result('method:Demo\\Noise::one', 'src/Fixture.php', 6, 1),
                    $this->result('method:Demo\\Noise::two', 'src/Fixture.php', 7, 2),
                    $this->result('method:Demo\\Noise::three', 'src/Fixture.php', 8, 3),
                    $this->result('method:Demo\\Noise::four', 'src/Fixture.php', 9, 4),
                    $this->result('method:Demo\\LeafTest::testRead', 'tests/LeafTest.php', 6, 5),
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

    /** @return array<string, mixed> */
    private function result(string $symbolId, string $path, int $line, int $rank): array
    {
        return [
            'symbol_id' => $symbolId,
            'file_path' => $path,
            'start_line' => $line,
            'end_line' => $line,
        ];
    }

    /** @param list<SymbolEntry> $symbols */
    private function file(string $path, array $symbols): FileEntry
    {
        $hash = hash_file('sha256', $this->root . '/' . $path);
        self::assertIsString($hash);

        return new FileEntry($path, 'sha256:' . $hash, 'Demo', $symbols, 'analysed');
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
