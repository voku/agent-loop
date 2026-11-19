<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * MEMORY.md is an index into executable truth: each row names the canonical home that
 * enforces it. A layout move once invalidated four of those pointers at the same time
 * (finding.2026-09-04.100138), and nothing noticed, because only edits to MEMORY.md
 * itself were gated. Every backticked home must therefore resolve:
 *
 * - a repository path (contains `/`) must exist;
 * - a bare file name (`FooTest.php`) must exist somewhere in the repository;
 * - a class (`WorkflowStartCommand`) must have a `WorkflowStartCommand.php`, and a method
 *   reference (`Class::method()`) must be declared in that file.
 *
 * `vendor/bin/...` homes describe the installed-consumer contract, not this checkout.
 *
 * @internal
 */
final class MemoryCanonicalHomesResolveTest extends TestCase
{
    private const array HOME_COLUMNS = ['Canonical home', 'Promoted to'];

    public function testEveryCanonicalHomeInMemoryResolves(): void
    {
        $root = dirname(__DIR__);
        $memory = file_get_contents($root . '/MEMORY.md');
        self::assertIsString($memory);
        $homes = self::canonicalHomes($memory);
        self::assertNotEmpty($homes, 'MEMORY.md has no canonical-home column; the parser no longer matches the file.');

        $files = self::repositoryFiles($root);
        $unresolved = [];
        foreach ($homes as $home) {
            $reason = self::unresolvedReason($home, $root, $files);
            if ($reason !== null) {
                $unresolved[] = '`' . $home . '`: ' . $reason;
            }
        }

        self::assertSame([], $unresolved, "MEMORY.md names canonical homes that no longer exist. Move the row with the code:\n" . implode("\n", $unresolved));
    }

    public function testTheResolverRejectsAMovedPathAMissingClassAndAMissingMethod(): void
    {
        $root = dirname(__DIR__);
        $files = self::repositoryFiles($root);

        self::assertNotNull(self::unresolvedReason('docs/agents/project-instructions.md', $root, $files));
        self::assertNotNull(self::unresolvedReason('NoSuchWorkflowCommand', $root, $files));
        self::assertNotNull(self::unresolvedReason('WorkflowCloseCommand::noSuchGate()', $root, $files));
        self::assertNull(self::unresolvedReason('WorkflowCloseReadinessInspector::checkReviewGate()', $root, $files));
        self::assertNull(self::unresolvedReason('tests/MemoryTableCellTest.php', $root, $files));
    }

    /**
     * @return list<string>
     */
    private static function canonicalHomes(string $memory): array
    {
        $homes = [];
        $column = null;
        foreach (preg_split('/\R/', $memory) ?: [] as $line) {
            if (!str_starts_with(trim($line), '|')) {
                $column = null;
                continue;
            }
            $cells = array_map(trim(...), explode('|', trim(trim($line), '|')));
            if ($column === null) {
                $column = self::homeColumn($cells);
                continue;
            }
            $cell = $cells[$column] ?? '';
            if (preg_match_all('/`([^`]+)`/', $cell, $matches) > 0) {
                array_push($homes, ...$matches[1]);
            }
        }

        return array_values(array_unique($homes));
    }

    /**
     * The index of the canonical-home column in a table header row, or null for any other row.
     *
     * @param list<string> $cells
     */
    private static function homeColumn(array $cells): ?int
    {
        foreach (self::HOME_COLUMNS as $header) {
            $index = array_search($header, $cells, true);
            if (is_int($index)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $files basename => relative paths
     */
    private static function unresolvedReason(string $home, string $root, array $files): ?string
    {
        if (str_starts_with($home, 'vendor/')) {
            return null;
        }
        if (str_contains($home, '/')) {
            return file_exists($root . '/' . rtrim($home, '/')) ? null : 'path does not exist';
        }
        if (preg_match('/^[\w.-]+\.(php|md|json|ya?ml|sh)$/', $home) === 1) {
            return isset($files[$home]) ? null : 'no file with this name';
        }
        if (preg_match('/^(?<class>[A-Z]\w*)(?:::(?<method>\w+)\(\))?$/', $home, $match) === 1) {
            return self::unresolvedSymbol($match['class'], $match['method'] ?? null, $root, $files);
        }

        return null;
    }

    /**
     * @param array<string, list<string>> $files
     */
    private static function unresolvedSymbol(string $class, ?string $method, string $root, array $files): ?string
    {
        $candidates = $files[$class . '.php'] ?? [];
        if ($candidates === []) {
            return 'no ' . $class . '.php';
        }
        if ($method === null || self::declaresMethod($candidates, $method, $root)) {
            return null;
        }

        return 'method ' . $method . '() is not declared in ' . $class . '.php';
    }

    /**
     * @param list<string> $candidates
     */
    private static function declaresMethod(array $candidates, string $method, string $root): bool
    {
        $declaration = '/function\s+' . preg_quote($method, '/') . '\s*\(/';
        foreach ($candidates as $candidate) {
            if (preg_match($declaration, (string) file_get_contents($root . '/' . $candidate)) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, list<string>>
     */
    private static function repositoryFiles(string $root): array
    {
        $files = [];
        // Prune excluded trees before descending into them, so vendor/ and .git/ are never walked.
        $directories = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => preg_match('#^(vendor|\.git|var|build|tools/[^/]+/vendor)$#', substr($file->getPathname(), strlen($root) + 1)) !== 1,
        );
        foreach (new RecursiveIteratorIterator($directories) as $file) {
            $files[$file->getFilename()][] = substr($file->getPathname(), strlen($root) + 1);
        }

        return $files;
    }
}
