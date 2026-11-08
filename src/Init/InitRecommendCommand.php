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
     *   quality: array{source: string|null, composer_scripts: list<string>, requires_confirmation: true, note: string},
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
        $composer = $composerPath === null ? null : $this->readJson($composerPath);
        $phpstanLists = $this->phpstanLists($phpstanPath);
        [$paths, $source] = $this->mapPaths($phpstanLists['paths'], $phpstanPath, $composerPath, $composer);
        $slop = $this->slopIgnores($slopPath);

        $phpstanExcludes = $phpstanLists['excludes'];
        $slopIgnores = $slop['ignores'];
        $warnings = [...$phpstanLists['warnings'], ...$slop['warnings']];

        // Validate and read exactly once: InitConfigLoader owns interpretation,
        // while this command owns bounded input and repository confinement.
        $initContent = $initPath === null ? null : $this->readBounded($initPath);
        $config = (new InitConfigLoader($root))->load($initPath, $initContent);
        array_push($warnings, ...$config['warnings']);
        $language = $config['prompting']['language'];

        $mapCommand = $paths === [] ? null : 'vendor/bin/agent-loop map build --paths=' . implode(',', $paths);
        $actions = ['vendor/bin/agent-loop init sync-tools --dry-run'];
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
            'quality' => [
                'source' => $composerPath,
                'composer_scripts' => $composer === null ? [] : (new InitComposerQualityEvidence())->scriptNames($composer),
                'requires_confirmation' => true,
                'note' => 'Composer script names are declarations, not verified safe commands. Inspect script bodies and CI before executing them; never run scripts during discovery.',
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

    /**
     * Keep unsupported PHPStan expressions visible instead of silently treating
     * their paths or exclusions as authoritative.
     *
     * @return array{paths: list<string>, excludes: list<string>, warnings: list<string>}
     */
    private function phpstanLists(?string $configPath): array
    {
        if ($configPath === null) {
            return ['paths' => [], 'excludes' => [], 'warnings' => []];
        }

        $content = $this->readBounded($configPath);
        $paths = $this->neonList($content, 'paths');
        $excludes = $this->neonList($content, 'excludePaths');
        $warnings = [];
        if ($paths === null) {
            $warnings[] = 'PHPStan parameters.paths could not be read as a literal list; falling back to Composer or directory scope.';
        }
        if ($excludes === null) {
            $warnings[] = 'PHPStan parameters.excludePaths could not be read as a literal list; no excludes are reported.';
        }

        return ['paths' => $paths ?? [], 'excludes' => $excludes ?? [], 'warnings' => $warnings];
    }

    /**
     * Prefer the verified PHPStan scope, then Composer production autoload.
     * A development-only project may use its separately declared autoload-dev
     * directories when no production scope is available.
     *
     * @param list<string> $phpstanPaths
     * @param array<string, mixed>|null $composer
     * @return array{list<string>, string|null}
     */
    private function mapPaths(array $phpstanPaths, ?string $phpstanPath, ?string $composerPath, ?array $composer): array
    {
        $paths = $this->existingPaths($phpstanPaths);
        if ($paths !== []) {
            return [$paths, $phpstanPath];
        }

        if ($composer !== null) {
            foreach (['autoload', 'autoload-dev'] as $section) {
                $paths = $this->composerPsr4Paths($composer, $section);
                if ($paths !== []) {
                    $source = $section === 'autoload' ? $composerPath : $composerPath . ':autoload-dev';

                    return [$paths, $source];
                }
            }
        }

        $paths = $this->existingPaths(['src', 'tests']);

        return [$paths, $paths !== [] ? 'directory-presence' : null];
    }

    /**
     * @param array<string, mixed> $composer
     * @return list<string>
     */
    private function composerPsr4Paths(array $composer, string $section): array
    {
        $autoload = $composer[$section] ?? null;
        $psr4 = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;
        $candidates = [];

        foreach (is_array($psr4) ? $psr4 : [] as $directories) {
            foreach (is_array($directories) ? $directories : [$directories] as $directory) {
                if (is_string($directory)) {
                    $candidates[] = $directory;
                }
            }
        }

        return $this->existingPaths($candidates);
    }

    /**
     * A Slop Scan ignore is evidence of Slop policy only, not an exclusion
     * eligible for automatic propagation to Map or PHPStan.
     *
     * @return array{ignores: list<string>, warnings: list<string>}
     */
    private function slopIgnores(?string $configPath): array
    {
        if ($configPath === null) {
            return ['ignores' => [], 'warnings' => []];
        }

        $slop = $this->readJson($configPath);
        $ignores = $slop['ignores'] ?? [];
        if (!is_array($ignores) || array_filter($ignores, static fn (mixed $ignore): bool => !is_string($ignore)) !== []) {
            return ['ignores' => [], 'warnings' => ['slop-scan ignores are not a list of strings; no excludes were adopted.']];
        }

        return ['ignores' => array_values($ignores), 'warnings' => []];
    }

    /** @param list<string> $candidates */
    private function firstExisting(array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $absolute = rtrim($this->rootPath, '/') . '/' . $candidate;
            if (is_link($absolute)) {
                throw new RuntimeException('Symlinked project config is not supported: ' . $candidate);
            }
            if (!file_exists($absolute)) {
                continue;
            }
            // Keep a colliding directory from silently selecting a fallback.
            $this->safeConfigPath($candidate);

            return $candidate;
        }

        return null;
    }

    /**
     * Resolve repository-owned evidence only. This also rejects parent-directory
     * symlinks (for example .agent-loop -> /outside) and dangling inputs.
     */
    private function safeConfigPath(string $path): string
    {
        $root = realpath($this->rootPath);
        if ($root === false) {
            throw new RuntimeException('Project root does not exist.');
        }

        $absolute = rtrim($root, '/') . '/' . $path;
        $resolved = realpath($absolute);
        if ($resolved === false || !is_file($resolved) || !str_starts_with($resolved, rtrim($root, '/') . '/')) {
            throw new RuntimeException('Config must be a readable repository file: ' . $path);
        }

        return $resolved;
    }

    private function readBounded(string $path): string
    {
        $absolute = $this->safeConfigPath($path);
        clearstatcache(true, $absolute);
        $size = filesize($absolute);
        if ($size === false || $size > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('Config is unreadable or too large: ' . $path);
        }

        $content = file_get_contents($absolute);
        if (!is_string($content) || strlen($content) > self::MAX_CONFIG_BYTES) {
            throw new RuntimeException('Config is unreadable or too large: ' . $path);
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
     * Extract only literal PHPStan list entries without evaluating NEON includes,
     * parameters or expressions. Null means the requested key was present but
     * could not be interpreted safely; an absent or empty key returns [].
     *
     * @return list<string>|null
     */
    private function neonList(string $content, string $key): ?array
    {
        $lines = preg_split('/\R/', $content);
        if ($lines === false) {
            return null;
        }

        $inParameters = false;
        $parameterChildIndent = null;
        $seen = false;
        $keyIndent = 0;
        $itemIndent = null;
        $subkeyIndent = null;
        $values = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }
            if ($line === 'parameters:') {
                $inParameters = true;
                $parameterChildIndent = null;
                continue;
            }
            if (preg_match('/^\S/', $line) === 1) {
                if ($seen) {
                    break;
                }
                $inParameters = false;
            }
            if (!$inParameters) {
                continue;
            }
            if (str_contains($line, "\t")) {
                return null;
            }

            if (!$seen) {
                // A nested `paths:` is not `parameters.paths`. Establish the
                // direct-child indent from the first actual parameter entry.
                $indent = strspn($line, ' ');
                if ($parameterChildIndent === null) {
                    if ($indent !== 2 && $indent !== 4) {
                        return null;
                    }
                    $parameterChildIndent = $indent;
                }
                if ($indent !== $parameterChildIndent) {
                    continue;
                }
                if (preg_match('/^( {2}| {4})' . preg_quote($key, '/') . ':(.*)$/', $line, $field) !== 1) {
                    continue;
                }
                $seen = true;
                $keyIndent = strlen($field[1]);
                $inline = trim($field[2]);
                if ($inline === '[]') {
                    return [];
                }
                if ($inline !== '' && !str_starts_with($inline, '#')) {
                    return null;
                }
                continue;
            }

            $indent = strspn($line, ' ');
            if ($indent <= $keyIndent) {
                break;
            }

            if ($key === 'excludePaths'
                && preg_match('/^(?:analyse|analyseAndScan):\s*(?:#.*)?$/', $trimmed) === 1
                && $indent === $keyIndent + 2
            ) {
                $subkeyIndent = $indent;
                $itemIndent = null;
                continue;
            }

            $minimumIndent = ($subkeyIndent ?? $keyIndent) + 2;
            if ($indent < $minimumIndent || preg_match('/^-\s+(.+)$/', $trimmed, $entry) !== 1) {
                return null;
            }
            if ($itemIndent !== null && $indent !== $itemIndent) {
                return null;
            }
            $itemIndent = $indent;

            $literalEntry = $entry[1];
            if ($key === 'paths' && str_starts_with($literalEntry, '%currentWorkingDirectory%/')) {
                $literalEntry = substr($literalEntry, strlen('%currentWorkingDirectory%/'));
            }

            if (preg_match('/\A(?:([a-zA-Z0-9_.*\/-]+)|"([a-zA-Z0-9_.*\/-]+)"|\'([a-zA-Z0-9_.*\/-]+)\')(?:\s+#.*)?\z/', $literalEntry, $literal) !== 1) {
                return null;
            }
            $values[] = $literal[1] ?: (($literal[2] ?? '') ?: ($literal[3] ?? ''));
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
            if ($path === '' || str_starts_with($path, '/') || preg_match('/\A[a-zA-Z0-9_.\/-]+\z/', $path) !== 1 || in_array('..', explode('/', $path), true)) {
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
