<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentMap\IO\SourceLanguage;

/**
 * Read-only evidence from a Node project (`package.json`), never setup authority.
 *
 * Nothing is executed and no package manager is asked: script names are declarations, and
 * which files count as TypeScript/JavaScript is agent-map's own file-to-language mapping.
 */
final readonly class InitNodeProjectEvidence
{
    /** Conventional source and test roots; only existing ones that hold indexable files are reported. */
    private const array SOURCE_DIRECTORIES = ['src', 'lib', 'app', 'test', 'tests'];

    /** Directories that hold dependencies, VCS state or compiled output, not project source. */
    private const array SKIPPED_DIRECTORIES = ['node_modules', 'vendor', '.git', 'dist', 'build'];

    /** A discovery command must stay cheap on a large tree; past this it reports what it saw. */
    private const int MAX_SCANNED_ENTRIES = 20000;

    /**
     * Script names a Node project conventionally uses for validation, in the shape Composer
     * quality scripts are reported: names only, bodies unverified.
     *
     * @param array<string, mixed> $package decoded package.json
     *
     * @return list<string>
     */
    public function scriptNames(array $package): array
    {
        $scripts = $package['scripts'] ?? null;
        if (!is_array($scripts)) {
            return [];
        }

        $names = [];
        foreach ($scripts as $name => $command) {
            if (!is_string($name) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:-]{0,63}\z/', $name) !== 1) {
                continue;
            }
            if (preg_match('/\A(?:typecheck|build|test|lint|verify)(?:[:.-][a-zA-Z0-9_.-]+)*\z/i', $name) !== 1) {
                continue;
            }
            if (!is_string($command) || trim($command) === '') {
                continue;
            }

            $names[] = $name;
        }

        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Existing conventional directories per language agent-map can index, found by a bounded
     * scan that follows no symlinks.
     *
     * @return array<string, list<string>> language => project-relative directories
     */
    public function sourceDirectories(string $root): array
    {
        $root = rtrim($root, '/');
        $found = [];
        $budget = self::MAX_SCANNED_ENTRIES;

        foreach (self::SOURCE_DIRECTORIES as $directory) {
            $absolute = $root . '/' . $directory;
            if (is_link($absolute) || !is_dir($absolute)) {
                continue;
            }

            foreach ($this->languagesBelow($absolute, $budget) as $language) {
                $found[$language][] = $directory;
            }
        }

        return $found;
    }

    /**
     * @return list<string> languages with at least one indexable file below `$directory`
     */
    private function languagesBelow(string $directory, int &$budget): array
    {
        $skipped = self::SKIPPED_DIRECTORIES;
        $files = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => !$entry->isLink()
                && !($entry->isDir() && in_array($entry->getFilename(), $skipped, true)),
        ));

        $languages = [];
        foreach ($files as $file) {
            if (--$budget < 0 || count($languages) === count(SourceLanguage::EXTENSIONS)) {
                break;
            }
            $language = $file instanceof SplFileInfo && $file->isFile()
                ? SourceLanguage::ofPath($file->getFilename())
                : null;
            if ($language !== null) {
                $languages[$language] = true;
            }
        }

        return array_keys($languages);
    }
}
