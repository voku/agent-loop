<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use RuntimeException;

/**
 * Generic MEMORY.md promotion reviewer.
 *
 * The review depends on a small Markdown contract. Validation and review share
 * the same parser so an unrecognized or malformed table can never be confused
 * with an empty, clean review queue.
 */
final class MemoryPromotionAnalyzer
{
    /**
     * @var list<string>
     */
    private const array DURABLE_HEADERS = ['Subject', 'Durable rule', 'Canonical home'];

    /**
     * @var list<string>
     */
    private const array ARCHIVE_HEADERS = [
        'Archived on',
        'Task',
        'Summary',
        'Archive reason',
        'Durable lesson candidate',
        'Promoted to',
    ];

    private const string DURABLE_HEADING = '## Durable repository rules';

    /**
     * @var list<string>
     */
    private const array ARCHIVE_HEADINGS = ['## Archived task learning', '## Archive'];

    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @param list<string> $tokens tokens after the `memory` namespace
     */
    public function run(array $tokens): int
    {
        $command = $tokens[0] ?? 'review';

        if ($command === 'help' || $command === '--help' || $command === '-h') {
            echo $this->usage();

            return 0;
        }

        if (!in_array($command, ['review', 'validate'], true)) {
            fwrite(\STDERR, "Unknown memory command: {$command}\n\n");
            fwrite(\STDERR, $this->usage());

            return 1;
        }

        $file = $this->resolveFile($tokens);

        if (!is_file($file)) {
            fwrite(\STDERR, "[ERROR] MEMORY file not found: {$file}\n");

            return 1;
        }

        $content = file_get_contents($file);
        if ($content === false || trim($content) === '') {
            fwrite(\STDERR, "[ERROR] MEMORY file is empty or unreadable: {$file}\n");

            return 1;
        }

        try {
            $analysis = $this->analyze($content);
        } catch (RuntimeException $exception) {
            fwrite(\STDERR, '[ERROR] Invalid MEMORY structure: ' . $exception->getMessage() . "\n");

            return 1;
        }

        if ($command === 'validate') {
            echo "# MEMORY validation\n\n";
            echo '[OK] MEMORY structure is valid: '
                . count($analysis['durableRows']) . ' durable rule(s), '
                . count($analysis['archiveRows']) . " archived task row(s).\n";

            return 0;
        }

        return $this->review($analysis['durableRows'], $analysis['archiveRows']);
    }

    /**
     * @return array{
     *   durableRows: list<array<string, string>>,
     *   archiveRows: list<array<string, string>>
     * }
     */
    private function analyze(string $content): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $durableHeading = $this->findHeading($lines, [self::DURABLE_HEADING]);
        $archiveHeading = $this->findHeading($lines, self::ARCHIVE_HEADINGS);

        if ($durableHeading === null && $archiveHeading === null) {
            throw new RuntimeException('no supported MEMORY section found');
        }

        $durableRows = $durableHeading === null
            ? []
            : $this->parseTableInSection($lines, $durableHeading, self::DURABLE_HEADERS, 'durable rules');
        $this->assertUniqueDurableSubjects($durableRows);

        $archiveRows = $archiveHeading === null
            ? []
            : $this->parseTableInSection($lines, $archiveHeading, self::ARCHIVE_HEADERS, 'archived task learning');

        return [
            'durableRows' => $durableRows,
            'archiveRows' => $archiveRows,
        ];
    }

    /**
     * @param list<array<string, string>> $durableRows
     * @param list<array<string, string>> $archiveRows
     */
    private function review(array $durableRows, array $archiveRows): int
    {
        $pendingRows = array_values(array_filter(
            $archiveRows,
            static function (array $row): bool {
                $promotedTo = strtolower(trim($row['Promoted to'] ?? ''));

                return $promotedTo === 'pending review' || $promotedTo === 'this file';
            }
        ));

        echo "# MEMORY promotion review\n\n";
        echo 'Durable repository rules: ' . count($durableRows) . "\n";
        echo 'Archived task rows: ' . count($archiveRows) . "\n";
        echo 'Rows still needing promotion review: ' . count($pendingRows) . "\n\n";

        if ($pendingRows === []) {
            echo "[OK] No pending promotion rows found.\n";

            return 0;
        }

        echo "## Review queue\n\n";

        foreach ($pendingRows as $row) {
            echo '- Task: ' . trim($row['Task'] ?? '') . "\n";
            echo '  Lesson: ' . trim($row['Durable lesson candidate'] ?? '') . "\n";
            echo '  Current promoted-to: ' . trim($row['Promoted to'] ?? '') . "\n\n";
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function resolveFile(array $tokens): string
    {
        foreach ($tokens as $token) {
            if (str_starts_with($token, '--file=')) {
                $value = substr($token, strlen('--file='));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return rtrim($this->rootPath, '/') . '/MEMORY.md';
    }

    /**
     * @param list<string> $lines
     * @param list<string> $headings
     */
    private function findHeading(array $lines, array $headings): ?int
    {
        foreach ($lines as $index => $line) {
            if (in_array(trim($line), $headings, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<string> $lines
     * @param list<string> $headers
     *
     * @return list<array<string, string>>
     */
    private function parseTableInSection(array $lines, int $headingIndex, array $headers, string $label): array
    {
        $headerLine = '| ' . implode(' | ', $headers) . ' |';
        $headerIndex = null;

        for ($i = $headingIndex + 1; $i < count($lines); ++$i) {
            $line = trim($lines[$i]);
            if (str_starts_with($line, '## ')) {
                break;
            }
            if ($line === $headerLine) {
                $headerIndex = $i;
                break;
            }
            if ($line !== '' && str_starts_with($line, '|')) {
                throw new RuntimeException("{$label} table header does not match the supported columns");
            }
        }

        if ($headerIndex === null) {
            throw new RuntimeException("{$label} section has no supported table header");
        }

        $separatorIndex = $headerIndex + 1;
        if (!isset($lines[$separatorIndex])) {
            throw new RuntimeException("{$label} table has no separator row");
        }

        $separatorCells = $this->parseMarkdownTableRow(trim($lines[$separatorIndex]));
        if (count($separatorCells) !== count($headers)) {
            throw new RuntimeException("{$label} separator has the wrong number of columns");
        }
        foreach ($separatorCells as $cell) {
            if (preg_match('/^:?-{3,}:?$/', $cell) !== 1) {
                throw new RuntimeException("{$label} separator contains an invalid cell: {$cell}");
            }
        }

        $rows = [];
        for ($i = $headerIndex + 2; $i < count($lines); ++$i) {
            $rowLine = trim($lines[$i]);
            if ($rowLine === '' || str_starts_with($rowLine, '## ')) {
                break;
            }
            if (!str_starts_with($rowLine, '|')) {
                throw new RuntimeException("{$label} contains non-table content before the table ended");
            }

            $cells = $this->parseMarkdownTableRow($rowLine);
            if (count($cells) !== count($headers)) {
                throw new RuntimeException("{$label} row has the wrong number of columns: {$rowLine}");
            }

            /** @var array<string, string> $row */
            $row = array_combine($headers, $cells);
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param list<array<string, string>> $rows
     */
    private function assertUniqueDurableSubjects(array $rows): void
    {
        $subjects = [];
        foreach ($rows as $row) {
            $subject = trim($row['Subject'] ?? '');
            if ($subject === '') {
                throw new RuntimeException('durable rule has an empty Subject');
            }
            $key = strtolower($subject);
            if (isset($subjects[$key])) {
                throw new RuntimeException("duplicate durable rule Subject: {$subject}");
            }
            $subjects[$key] = true;
        }
    }

    /**
     * @return list<string>
     */
    private function parseMarkdownTableRow(string $line): array
    {
        if (!str_starts_with($line, '|') || !str_ends_with($line, '|')) {
            return [];
        }

        $trimmed = trim(trim($line), '|');

        return array_map(
            static fn (string $part): string => trim($part),
            explode('|', $trimmed)
        );
    }

    private function usage(): string
    {
        return <<<TXT
        agent-loop memory - MEMORY.md structure validation and promotion review.

        Usage:
          agent-loop memory validate [--file=path/to/MEMORY.md]
          agent-loop memory review   [--file=path/to/MEMORY.md]

        Review always validates first. Defaults to <root>/MEMORY.md when --file is omitted.

        TXT;
    }
}
