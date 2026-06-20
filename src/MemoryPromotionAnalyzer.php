<?php

declare(strict_types=1);

namespace voku\AgentLoop;

/**
 * Generic MEMORY.md promotion reviewer.
 *
 * Self-contained re-implementation of the host-specific
 * `review_memory_promotions.php` glue: it parses the durable-rule and archive
 * tables out of a Markdown MEMORY file and reports how many archived rows still
 * need a promotion decision. Unlike the host version it carries no project
 * specific autoload/global dependencies and no hard-coded promotion targets, so
 * consumers keep their own canonical-home heuristics where needed.
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

        if ($command !== 'review') {
            fwrite(\STDERR, "Unknown memory command: {$command}\n\n");
            fwrite(\STDERR, $this->usage());

            return 1;
        }

        $file = $this->resolveFile($tokens);

        if (!is_file($file)) {
            fwrite(\STDERR, "[ERROR] MEMORY file not found: {$file}\n");

            return 1;
        }

        $content = (string) file_get_contents($file);
        if (trim($content) === '') {
            fwrite(\STDERR, "[ERROR] MEMORY file is empty: {$file}\n");

            return 1;
        }

        return $this->review($content);
    }

    private function review(string $content): int
    {
        $durableRows = $this->parseAllMarkdownTables($content, self::DURABLE_HEADERS);

        $archiveTables = $this->parseAllMarkdownTables($content, self::ARCHIVE_HEADERS);
        $archiveRows = $archiveTables[0] ?? [];

        $pendingRows = array_values(array_filter(
            $archiveRows,
            static function (array $row): bool {
                $promotedTo = strtolower(trim($row['Promoted to'] ?? ''));

                return $promotedTo === 'pending review' || $promotedTo === 'this file';
            }
        ));

        echo "# MEMORY promotion review\n\n";
        echo 'Durable repository rules: ' . $this->countTableRows($durableRows) . "\n";
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
     * @param list<list<array<string, string>>> $tables
     */
    private function countTableRows(array $tables): int
    {
        $count = 0;
        foreach ($tables as $rows) {
            $count += count($rows);
        }

        return $count;
    }

    /**
     * @param list<string> $headers
     *
     * @return list<list<array<string, string>>>
     */
    private function parseAllMarkdownTables(string $content, array $headers): array
    {
        $lines = preg_split('/\R/', $content) ?: [];
        $headerLine = '| ' . implode(' | ', $headers) . ' |';
        $tables = [];

        foreach ($lines as $index => $line) {
            if (trim($line) !== $headerLine) {
                continue;
            }

            $rows = [];

            for ($i = $index + 2; $i < count($lines); ++$i) {
                $rowLine = trim($lines[$i]);
                if ($rowLine === '' || !str_starts_with($rowLine, '|')) {
                    break;
                }

                $cells = $this->parseMarkdownTableRow($rowLine);
                if (count($cells) !== count($headers)) {
                    break;
                }

                /** @var array<string, string> $row */
                $row = array_combine($headers, $cells);
                $rows[] = $row;
            }

            $tables[] = $rows;
        }

        return $tables;
    }

    /**
     * @return list<string>
     */
    private function parseMarkdownTableRow(string $line): array
    {
        $trimmed = trim(trim($line), '|');

        return array_map(
            static fn (string $part): string => trim($part),
            explode('|', $trimmed)
        );
    }

    private function usage(): string
    {
        return <<<TXT
        agent-loop memory - MEMORY.md promotion review.

        Usage:
          agent-loop memory review [--file=path/to/MEMORY.md]

        Defaults to <root>/MEMORY.md when --file is omitted.

        TXT;
    }
}
