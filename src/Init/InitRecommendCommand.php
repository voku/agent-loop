<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use JsonException;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionStateStore;

/**
 * Read-only setup evidence for a coding host or LLM to review.
 *
 * Configurations are input data, never PHP to execute. A path that is
 * excluded by PHPStan is NOT automatically excluded from slop-scan or
 * agent-map: the three tools intentionally use different semantics.
 */
final readonly class InitRecommendCommand
{
    private const int MAX_CONFIG_BYTES = 1048576;

    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $tokens */
    public function run(array $tokens): int
    {
        if ($tokens !== [] && $tokens !== ['--format=json']) {
            fwrite(STDERR, "[FAIL] init recommend: supported option is --format=json\n");

            return 1;
        }

        try {
            $report = $this->inspect();
            echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (JsonException | RuntimeException $exception) {
            fwrite(STDERR, '[FAIL] init recommend: ' . $exception->getMessage() . "\n");

            return 1;
        }
    }

    /**
     * @return array{
     *   schema_version: int,
     *   sources: array{phpstan: string|null, php_cs_fixer: string|null, composer: string|null, slop: string|null, agent_loop: string|null},
     *   map: array{paths: list<string>, source: string|null, command: string|null, requires_confirmation: true},
     *   exclusions: array{phpstan: list<string>, slop: list<string>, note: string},
     *   prompting: array{language: string, source: string},
     *   review: array{human_attention_every_changes_required: int, configurable_by_setup: false},
     *   next_actions: list<string>,
     *   questions: list<string>,
     *   warnings: list<string>
     * }
     */
    public function inspect(): array
    {
        $root = rtrim($this->rootPath, '/');
        $phpstanPath = $this->firstExisting(['phpstan.neon', 'phpstan.neon.dist']);
        $fixerPath = $this->firstExisting(['.php-cs-fixer.php', '.php-cs-fixer.dist.php']);
        $slopPath = $this->firstExisting(['slop-scan.config.json', 'repo-slop.config.json']);
        $composerPath = $this->firstExisting(['composer.json']);
        $initPath = $this->firstExisting(['.agent-loop/init.json']);
        $warnings = [];

        $phpstanPaths = [];
        $phpstanExcludes = [];
        if ($phpstanPath !== null) {
            $content = $this->readBounded($phpstanPath);
            $phpstanPaths = $this->neonList($content, 'paths');
            $phpstanExcludes = $this->neonList($content, 'excludePaths');
        }

        $paths = $this->existingPaths($phpstanPaths);
        $source = $paths !== [] ? $phpstanPath : null;

        if ($paths === [] && $composerPath !== null) {
            $composer = $this->readJson($composerPath);
            $autoload = $composer['autoload'] ?? null;
            $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
            $candidates = [];
            if (is_array($psr4)) {
                foreach ($psr4 as $directories) {
                    if (is_string($directories)) {
                        $candidates[] = $directories;
                    } elseif (is_array($directories)) {
                        foreach ($directories as $directory) {
                            if (is_string($directory)) {
                                $candidates[] = $directory;
                            }
                        }
                    }
                }
            }
            $paths = $this->existingPaths($candidates);
            $source = $paths !== [] ? $composerPath : null;
        }

        if ($paths === []) {
            $paths = $this->existingPaths(['src', 'tests']);
            $source = $paths !== [] ? 'directory-presence' : null;
        }

        $slopIgnores = [];
        if ($slopPath !== null) {
            $slop = $this->readJson($slopPath);
            $ignores = $slop['ignores'] ?? [];
            if (!is_array($ignores) || array_filter($ignores, static fn (mixed $ignore): bool => !is_string($ignore)) !== []) {
                $warnings[] = 'slop-scan ignores are not a list of strings; no excludes were adopted.';
            } else {
                $slopIgnores = array_values($ignores);
            }
        }

        $config = (new InitConfigLoader($root))->load($initPath);
        array_push($warnings, ...$config['warnings']);
        $language = $config['prompting']['language'];

        $mapCommand = $paths === [] ? null : 'agent-loop map build --paths=' . implode(',', $paths);
        $actions = ['agent-loop init sync-tools --dry-run'];
        if ($mapCommand !== null) {
            $actions[] = $mapCommand;
        }

        return [
            'schema_version' => 1,
            'sources' => [
                'phpstan' => $phpstanPath,
                'php_cs_fixer' => $fixerPath,
                'composer' => $composerPath,
                'slop' => $slopPath,
                'agent_loop' => $initPath,
            ],
            'map' => [
                'paths' => $paths,
                'source' => $source,
                'command' => $mapCommand,
                'requires_confirmation' => true,
            ],
            'exclusions' => [
                'phpstan' => $phpstanExcludes,
                'slop' => $slopIgnores,
                'note' => 'PHPStan excludePaths, PHP-CS-Fixer Finder excludes, slop globs and agent-map regexes are not interchangeable. Never copy excludes automatically. PHP-CS-Fixer config is detected, not executed.',
            ],
            'prompting' => [
                'language' => $language,
                'source' => $initPath === null ? 'default' : $initPath,
            ],
            'review' => [
                'human_attention_every_changes_required' => ExecutionStateStore::MAX_REVIEW_ROUNDS,
                'configurable_by_setup' => false,
            ],
            'next_actions' => $actions,
            'questions' => [
                'Confirm the agent-map indexed paths and any additional scan-only dependency paths.',
                'Review PHPStan / PHP-CS-Fixer exclusions before proposing slop-scan ignores; keep security findings visible.',
                'Confirm the preferred prompt language; a default is not a detected human preference.',
                'Keep the governed review Attention cap separate from any requested number of independent reviewers.',
            ],
            'warnings' => $warnings,
        ];
    }

    /** @param list<string> $candidates */
    private function firstExisting(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_file(rtrim($this->rootPath, '/') . '/' . $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function readBounded(string $path): string
    {
        $absolute = rtrim($this->rootPath, '/') . '/' . $path;
        $size = filesize($absolute);
        if ($size === false || $size > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('Config is unreadable or too large: ' . $path);
        }

        $content = file_get_contents($absolute);
        if (!is_string($content)) {
            throw new RuntimeException('Cannot read config: ' . $path);
        }

        return $content;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $content = $this->readBounded($path);
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !str_starts_with(ltrim($content), '{')) {
            throw new RuntimeException('Config must be a JSON object: ' . $path);
        }

        return $decoded;
    }

    /**
     * Extract only simple literal list entries. NEON includes, parameters,
     * expressions and nested configuration are intentionally not interpreted.
     *
     * @return list<string>
     */
    private function neonList(string $content, string $key): array
    {
        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            return [];
        }

        $inParameters = false;
        $inList = false;
        $values = [];
        foreach ($lines as $line) {
            if (preg_match('/^parameters:\s*$/', $line) === 1) {
                $inParameters = true;
                $inList = false;

                continue;
            }
            if (preg_match('/^\S/', $line) === 1) {
                $inParameters = false;
                $inList = false;
            }
            if (!$inParameters) {
                continue;
            }
            if (preg_match('/^  ' . preg_quote($key, '/') . ':\s*$/', $line) === 1) {
                $inList = true;

                continue;
            }
            if (!$inList) {
                continue;
            }
            if (preg_match('/^ {4,6}-\s+([a-zA-Z0-9_.*\/-]+)\s*(?:#.*)?$/', $line, $matches) === 1) {
                $values[] = $matches[1];

                continue;
            }
            if (trim($line) !== '' && !str_starts_with(ltrim($line), '#') && preg_match('/^ {4,}/', $line) !== 1) {
                $inList = false;
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @param list<string> $candidates
     * @return list<string>
     */
    private function existingPaths(array $candidates): array
    {
        $paths = [];
        $root = realpath($this->rootPath);
        if ($root === false) {
            throw new RuntimeException('Project root does not exist.');
        }

        foreach ($candidates as $candidate) {
            $path = rtrim(str_replace('\\', '/', $candidate), '/');
            if ($path === '.') {
                // A literal project-root scope is valid, but should not be expanded to
                // a guessed list of directories. The caller must confirm it.
                return ['.'];
            }
            if ($path === '' || preg_match('/\A[a-zA-Z0-9_.\/-]+\z/', $path) !== 1 || in_array('..', explode('/', $path), true)) {
                continue;
            }
            $resolved = realpath($root . '/' . $path);
            if ($resolved === false || !is_dir($resolved) || !str_starts_with($resolved, $root . '/')) {
                continue;
            }
            $paths[] = $path;
        }

        return array_values(array_unique($paths));
    }
}
