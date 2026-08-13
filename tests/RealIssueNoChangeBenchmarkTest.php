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

final class RealIssueNoChangeBenchmarkTest extends TestCase
{
    public function testIssue105KeepsTheExistingAstApiVisible(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/fixtures/real-issue-no-change/simple-php-code-parser-105.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(31659131414, $fixture['source']['run_id']);
        self::assertSame('NO_PRODUCTION_CHANGE_REQUIRED', $fixture['expected_decision']);
        self::assertFalse($fixture['evidence']['production_mutation_required']);

        $root = sys_get_temp_dir() . '/agent-loop-issue-105-' . bin2hex(random_bytes(8));
        mkdir($root . '/.agent-loop/map', 0777, true);
        try {
            $testMethod = new MethodEntry('testGetAstResolvesNamesAndKeepsFilePositions', 'public', 78, 116, reconciliationStatus: 'confirmed');
            $testClass = new SymbolEntry('class', 'ParserEntryPointsTest', 'voku\\tests\\ParserEntryPointsTest', 1, 220, [$testMethod], reconciliationStatus: 'confirmed');
            $astMethod = new MethodEntry('getAstFromString', 'public', 61, 77, reconciliationStatus: 'confirmed');
            $parserClass = new SymbolEntry('class', 'PhpCodeParser', 'voku\\SimplePhpParser\\Parsers\\PhpCodeParser', 24, 304, [$astMethod], reconciliationStatus: 'confirmed');
            $testFile = new FileEntry('tests/ParserEntryPointsTest.php', 'sha256:test', 'voku\\tests', [$testClass], 'analysed');
            $parserFile = new FileEntry('src/voku/SimplePhpParser/Parsers/PhpCodeParser.php', 'sha256:parser', 'voku\\SimplePhpParser\\Parsers', [$parserClass], 'analysed');
            $relation = RelationEntry::create($testClass->methodId($testMethod), 'calls', [$parserClass->methodId($astMethod)], 'tests/ParserEntryPointsTest.php', 93, 93, 'phpstan_resolved');
            (new IndexWriter())->write(new AgentMapIndex('2.0', $root, 'phpstan+simple-parser', [$parserFile, $testFile], [$relation], new AnalysisFingerprint('2.2.0', 'sha256:config', 'sha256:lock', 'sha256:issue-105')), $root . '/.agent-loop/map/php-symbols.json');

            $results = [];
            for ($rank = 1; $rank < 6; ++$rank) {
                $results[] = ['symbol_id' => 'class:Ignored' . $rank, 'file_path' => 'irrelevant.php', 'start_line' => 1, 'end_line' => 1];
            }
            $results[] = ['symbol_id' => $fixture['evidence']['ranked_test']['symbol_id'], 'file_path' => 'tests/ParserEntryPointsTest.php', 'start_line' => 78, 'end_line' => 116];

            $budget = new WorkflowContextBudget(120, 12000);
            (new WorkflowRankedMapContextExpander($root))->add($budget, ['payload' => ['status' => 'ranked', 'map_snapshot' => 'sha256:issue-105', 'results' => $results]]);
            $rendered = implode("\n", $budget->lines());

            self::assertStringContainsString('rank 6 test evidence calls voku\\SimplePhpParser\\Parsers\\PhpCodeParser::getAstFromString', $rendered);
            self::assertStringNotContainsString('seed voku\\tests\\ParserEntryPointsTest::testGetAstResolvesNamesAndKeepsFilePositions', $rendered);
        } finally {
            $this->removeDirectory($root);
        }
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
