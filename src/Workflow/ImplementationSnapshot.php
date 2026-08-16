<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;

/** Content identity for the approved implementation scope, independent of Git. */
final readonly class ImplementationSnapshot
{
    private const string VERSION = '1.0';

    /** @param list<array{path: string, sha256: string}> $files */
    private function __construct(
        public int $contractRevision,
        public array $files,
        public string $digest,
    ) {
    }

    public static function capture(string $rootPath, TaskContract $contract): self
    {
        $root = realpath($rootPath);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Implementation snapshot project root does not exist: ' . $rootPath);
        }
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $layout = new ProjectLayout($root);
        $stateRoot = str_replace('\\', '/', $layout->stateRoot());
        $stateRelative = self::relativeIfOwned($root, $stateRoot);
        $learningRelative = self::relativeIfOwned($root, $layout->learningRoot());
        $projectStateFiles = array_values(array_filter([
            self::relativeIfOwned($root, $layout->configPath()),
            self::relativeIfOwned($root, $layout->gitHooksConfig()),
        ]));

        /** @var array<string, string> $files */
        $files = [];
        foreach ($contract->scope as $scopePath) {
            $relative = self::normalizeRelative($scopePath);
            $absolute = $root . '/' . $relative;
            if (is_link($absolute)) {
                throw new RuntimeException('Implementation snapshot refuses symlinked scope: ' . $relative);
            }
            if (is_file($absolute)) {
                if (self::excludedExplicitFile($relative, $stateRelative, $learningRelative, $projectStateFiles)) {
                    throw new RuntimeException('Implementation snapshot scope is workflow/dependency metadata, not implementation content: ' . $relative);
                }
                $files[$relative] = self::hashFile($absolute, $relative);
                continue;
            }
            if (self::excluded($relative, $stateRelative)) {
                throw new RuntimeException('Implementation snapshot scope is workflow/dependency metadata, not implementation content: ' . $relative);
            }
            if (!is_dir($absolute)) {
                throw new RuntimeException('Implementation snapshot scoped path does not exist: ' . $relative);
            }

            $before = count($files);
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($absolute, RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $item) {
                if (!$item instanceof SplFileInfo) {
                    continue;
                }
                $path = str_replace('\\', '/', $item->getPathname());
                $fileRelative = self::relative($root, $path);
                if ($item->isLink()) {
                    throw new RuntimeException('Implementation snapshot refuses symlinked path inside approved scope: ' . $fileRelative);
                }
                if (!$item->isFile()) {
                    continue;
                }
                if (self::excluded($fileRelative, $stateRelative)) {
                    continue;
                }
                $files[$fileRelative] = self::hashFile($path, $fileRelative);
            }
            if (count($files) === $before) {
                throw new RuntimeException('Implementation snapshot scoped directory contains no implementation files: ' . $relative);
            }
        }
        if ($files === []) {
            throw new RuntimeException('Implementation snapshot requires at least one scoped implementation file.');
        }

        ksort($files, SORT_STRING);
        $entries = [];
        foreach ($files as $path => $sha256) {
            $entries[] = ['path' => $path, 'sha256' => $sha256];
        }
        $payload = [
            'schema_version' => self::VERSION,
            'contract_revision' => $contract->revision,
            'files' => $entries,
        ];
        $digest = 'sha256:' . hash('sha256', CanonicalJson::pretty($payload));

        return new self($contract->revision, $entries, $digest);
    }

    /** @return array{schema_version:string,contract_revision:int,files:list<array{path:string,sha256:string}>,digest:string} */
    public function toArray(): array
    {
        return [
            'schema_version' => self::VERSION,
            'contract_revision' => $this->contractRevision,
            'files' => $this->files,
            'digest' => $this->digest,
        ];
    }

    private static function normalizeRelative(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }
        if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1) {
            throw new RuntimeException('Implementation snapshot scope must be repository-relative: ' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Implementation snapshot scope escapes or ambiguously addresses the repository: ' . $path);
            }
        }

        return $path;
    }

    /**
     * Explicit project inputs under the workflow root may themselves be the
     * implementation being changed. Generated workflow state still may not.
     *
     * @param list<string> $projectStateFiles
     */
    private static function excludedExplicitFile(
        string $path,
        string $stateRelative,
        string $learningRelative,
        array $projectStateFiles,
    ): bool {
        if (self::inside($path, '.git') || self::inside($path, 'vendor')) {
            return true;
        }
        if ($stateRelative === '' || !self::inside($path, $stateRelative)) {
            return false;
        }
        if (in_array($path, $projectStateFiles, true)) {
            return false;
        }
        if (
            $learningRelative !== ''
            && self::inside($path, $learningRelative)
            && !self::inside($path, $learningRelative . '/history')
        ) {
            return false;
        }

        return true;
    }

    private static function excluded(string $path, string $stateRelative): bool
    {
        foreach (array_filter([$stateRelative, '.git', 'vendor']) as $excluded) {
            if (self::inside($path, $excluded)) {
                return true;
            }
        }

        return false;
    }

    private static function inside(string $path, string $root): bool
    {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function relativeIfOwned(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $root . '/')) {
            return '';
        }

        return substr($path, strlen($root) + 1);
    }

    private static function relative(string $root, string $path): string
    {
        $path = str_replace('\\', '/', $path);
        if (!str_starts_with($path, $root . '/')) {
            throw new RuntimeException('Implementation snapshot path escapes project root: ' . $path);
        }

        return substr($path, strlen($root) + 1);
    }

    private static function hashFile(string $absolute, string $relative): string
    {
        $hash = hash_file('sha256', $absolute);
        if ($hash === false) {
            throw new RuntimeException('Unable to hash implementation file: ' . $relative);
        }

        return 'sha256:' . $hash;
    }
}
