<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * MEMORY.md is a hand-edited file, so its parser has to accept the Markdown people
 * actually write.
 *
 * The row splitter used `trim($line, '|')`, which eats a *run* of pipes rather than
 * the one delimiter at each end. A row whose first or last cell is empty therefore
 * lost that column, and the analyzer rejected the whole file - `memory validate`
 * exits 1 and the promotion review never runs - over a table that renders correctly
 * everywhere else.
 *
 * Driven through the real binary so the reported reason is the one an operator sees.
 *
 * @internal
 */
final class MemoryTableCellTest extends TestCase
{
    private const string DURABLE_TABLE = "## Durable repository rules\n\n"
        . "| Subject | Durable rule | Canonical home |\n"
        . "| --- | --- | --- |\n";

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-memory-table-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testARowWhoseLastCellIsEmptyKeepsItsColumns(): void
    {
        [$exit, $stdout, $stderr] = $this->memory('validate', self::DURABLE_TABLE . "| Paths | Only ProjectLayout spells state paths ||\n");

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('1 durable rule(s)', $stdout);
    }

    public function testARowWhoseFirstCellIsEmptyIsStillReadAsThreeColumns(): void
    {
        // An empty Subject is a real violation - but of the uniqueness rule, reported as
        // such, not as a column-count problem in a table that has the right columns.
        [$exit, , $stderr] = $this->memory('validate', self::DURABLE_TABLE . "|| Only ProjectLayout spells state paths | src/ProjectLayout.php |\n");

        self::assertSame(1, $exit);
        self::assertStringContainsString('empty Subject', $stderr);
        self::assertStringNotContainsString('wrong number of columns', $stderr);
    }

    public function testAnEscapedPipeIsCellContentRatherThanADelimiter(): void
    {
        [$exit, $stdout, $stderr] = $this->memory('validate', self::DURABLE_TABLE . "| Paths | Accept a \\| b as one value | src/ProjectLayout.php |\n");

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('1 durable rule(s)', $stdout);
    }

    public function testAnEscapedPipeSurvivesUnescapedIntoTheReviewQueue(): void
    {
        [$exit, $stdout, $stderr] = $this->memory(
            'review',
            "## Archived task learning\n\n"
            . "| Archived on | Task | Summary | Archive reason | Durable lesson candidate | Promoted to |\n"
            . "| --- | --- | --- | --- | --- | --- |\n"
            . "| 2026-08-14 | T-1 | done | closed | Prefer a \\| b over c | pending review |\n",
        );

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('Prefer a | b over c', $stdout);
    }

    /** A row that really does have the wrong column count must still be rejected. */
    public function testAGenuinelyShortRowIsStillRejected(): void
    {
        [$exit, , $stderr] = $this->memory('validate', self::DURABLE_TABLE . "| Paths | Only ProjectLayout spells state paths |\n");

        self::assertSame(1, $exit);
        self::assertStringContainsString('wrong number of columns', $stderr);
    }

    /** The separator row is delimited the same way and must keep parsing. */
    public function testTheSeparatorRowStillValidates(): void
    {
        [$exit, , $stderr] = $this->memory(
            'validate',
            "## Durable repository rules\n\n"
            . "| Subject | Durable rule | Canonical home |\n"
            . "| :--- | ---: | :---: |\n"
            . "| Paths | Only ProjectLayout spells state paths | src/ProjectLayout.php |\n",
        );

        self::assertSame(0, $exit, $stderr);
    }

    /**
     * @return array{int, string, string}
     */
    private function memory(string $command, string $content): array
    {
        file_put_contents($this->root . '/MEMORY.md', $content);

        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', 'memory', $command],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start agent-loop binary.');
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if (!is_string($stdout) || !is_string($stderr)) {
            throw new RuntimeException('Unable to read agent-loop binary output.');
        }

        return [$exit, $stdout, $stderr];
    }
}
