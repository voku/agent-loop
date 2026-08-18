<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use ItpContext\Attribute\Rule;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\Context\ArchitectureRules;
use voku\AgentLoop\ProjectLayout;

/**
 * Probes whether common CLI tools are reachable in PATH and caches the result,
 * so agents do not have to re-probe availability at the start of every session.
 *
 * This is intentionally separate from InitDoctorCommand/InitStatusCommand: those
 * are read-only diagnostics of repo-managed agent assets and never write files.
 * InitToolsCommand's whole purpose is to write a small, gitignored cache file.
 */
#[Rule(ArchitectureRules::ExternalToolsStayOptional)]
#[Rule(ArchitectureRules::SingleStatePathOwner)]
final readonly class InitToolsCommand
{
    /**
     * @var list<string>
     */
    private const array KNOWN_TOOLS = ['rg', 'git', 'php', 'composer', 'docker'];

    /**
     * External agent-facing evidence tools. They are deliberately not
     * dependencies of this package, so a bare PATH probe would report the
     * layout that `docs/agents/dogfood/real-issue-acceptance.md` recommends as
     * "missing". Look where a project actually installs them.
     *
     * Project-local locations win over PATH: a repository that pinned the tool
     * meant that version, not whichever build happens to be ambient.
     *
     * `available` answers only whether the binary can be executed. Whether the
     * repository has anything for it to read is answered by running the tool.
     *
     * @var array<string, array{project_paths: list<string>, path_names: list<string>}>
     */
    private const array EXTERNAL_EVIDENCE_TOOLS = [
        'itp-context' => [
            'project_paths' => [
                'vendor/bin/itp-context-query',
                'tools/itp-context/vendor/bin/itp-context-query',
                'tools/agent-loop/vendor/bin/itp-context-query',
            ],
            'path_names' => ['itp-context-query'],
        ],
        'slop-scan' => [
            'project_paths' => [
                'vendor/bin/slop-scan.php',
                'tools/slop-scan/vendor/bin/slop-scan.php',
            ],
            'path_names' => ['slop-scan'],
        ],
    ];

    /**
     * These PHPStan extensions are useful to an agent only when they participate
     * in the host project's PHPStan process. Putting them in an isolated tools/
     * Composer project would create a second dependency graph and analyze the
     * wrong runtime. Presence here means direct root Composer configuration, not
     * that the package has already been installed into vendor/.
     *
     * @var array<string, non-empty-string>
     */
    private const array PROJECT_COMPOSER_TOOLS = [
        'voku/phpstan-agent-format' => 'compact agent-facing PHPStan formatter',
        'voku/phpstan-rules' => 'additional PHPStan rules',
    ];

    private const int DEFAULT_MAX_AGE_SECONDS = 3600;

    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        if (in_array('help', $tokens, true) || in_array('--help', $tokens, true) || in_array('-h', $tokens, true)) {
            echo $this->usage();

            return 0;
        }

        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $cachePath = $this->resolveCachePath(OptionTokens::value($tokens, 'cache'));
        $maxAge = $this->readMaxAge($tokens);
        $refresh = in_array('--refresh', $tokens, true);

        $cached = $this->readCache($cachePath);
        $useCache = !$refresh
            && $cached !== null
            && $this->isFresh($cached, $maxAge)
            && $this->coversEveryKnownTool($cached);

        $report = $useCache ? $cached : $this->probe($maxAge);
        if (!$useCache) {
            $this->writeCache($cachePath, $report);
        }

        echo "agent-loop init tools\n\n";
        echo $this->render($report, $useCache, $cachePath);
        echo $this->renderProjectComposerTools();

        return 0;
    }

    /**
     * @return array{generated_at: string, max_age_seconds: int, tools: array<string, array{available: bool, path: ?string}>, agent_map_index: array{present: bool, path: string, age_seconds: ?int}}
     */
    private function probe(int $maxAge): array
    {
        $tools = [];
        foreach (self::KNOWN_TOOLS as $tool) {
            $path = $this->resolveBinary($tool);
            $tools[$tool] = ['available' => $path !== null, 'path' => $path];
        }

        foreach (self::EXTERNAL_EVIDENCE_TOOLS as $tool => $candidates) {
            $path = $this->resolveExternalTool($candidates);
            $tools[$tool] = ['available' => $path !== null, 'path' => $path];
        }

        // The layout owner spells this path, and renders it. A second literal
        // here reported the pre-consolidation location and told agents an
        // existing index was never built.
        $layout = new ProjectLayout($this->rootPath);
        $absoluteMapIndexPath = $layout->mapIndex();
        $mapIndexPath = $layout->display($absoluteMapIndexPath);
        $mapIndexPresent = is_file($absoluteMapIndexPath);
        $mapIndexAge = $mapIndexPresent ? (time() - (int) filemtime($absoluteMapIndexPath)) : null;

        return [
            'generated_at' => date(\DATE_ATOM),
            'max_age_seconds' => $maxAge,
            'tools' => $tools,
            'agent_map_index' => [
                'present' => $mapIndexPresent,
                'path' => $mapIndexPath,
                'age_seconds' => $mapIndexAge,
            ],
        ];
    }

    /**
     * @param array{project_paths: list<string>, path_names: list<string>} $candidates
     */
    private function resolveExternalTool(array $candidates): ?string
    {
        foreach ($candidates['project_paths'] as $relativePath) {
            $candidate = $this->resolvePath($relativePath);
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        foreach ($candidates['path_names'] as $name) {
            $candidate = $this->resolveBinary($name);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveBinary(string $name): ?string
    {
        $pathEnv = getenv('PATH');
        if (!is_string($pathEnv) || $pathEnv === '') {
            return null;
        }

        $extensions = \PHP_OS_FAMILY === 'Windows' ? ['.exe', '.bat', '.cmd', ''] : [''];

        foreach (explode(\PATH_SEPARATOR, $pathEnv) as $directory) {
            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($directory, '/\\') . \DIRECTORY_SEPARATOR . $name . $extension;
                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @return array{generated_at: string, max_age_seconds: int, tools: array<string, array{available: bool, path: ?string}>, agent_map_index: array{present: bool, path: string, age_seconds: ?int}}|null
     */
    private function readCache(string $cachePath): ?array
    {
        if (!is_file($cachePath)) {
            return null;
        }

        $content = file_get_contents($cachePath);
        if (!is_string($content)) {
            return null;
        }

        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !isset($decoded['generated_at'], $decoded['tools']) || !is_string($decoded['generated_at']) || !is_array($decoded['tools'])) {
            return null;
        }

        /** @var array{generated_at: string, max_age_seconds: int, tools: array<string, array{available: bool, path: ?string}>, agent_map_index: array{present: bool, path: string, age_seconds: ?int}} $decoded */
        return $decoded;
    }

    /**
     * A cache written before a tool was known says nothing about that tool.
     * Reusing it would report a missing plane that is actually installed.
     *
     * @param array{generated_at: string, max_age_seconds: int, tools: array<string, array{available: bool, path: ?string}>, agent_map_index: array{present: bool, path: string, age_seconds: ?int}} $cached
     */
    private function coversEveryKnownTool(array $cached): bool
    {
        foreach ([...self::KNOWN_TOOLS, ...array_keys(self::EXTERNAL_EVIDENCE_TOOLS)] as $tool) {
            if (!array_key_exists($tool, $cached['tools'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{generated_at: string, max_age_seconds: int, tools: array<string, array{available: bool, path: ?string}>, agent_map_index: array{present: bool, path: string, age_seconds: ?int}} $cached
     */
    private function isFresh(array $cached, int $maxAge): bool
    {
        $generatedAt = date_create($cached['generated_at']);
        if ($generatedAt === false) {
            return false;
        }

        return (time() - $generatedAt->getTimestamp()) < $maxAge;
    }

    /**
     * @param array{generated_at: string, max_age_seconds: int, tools: array<string, array{available: bool, path: ?string}>, agent_map_index: array{present: bool, path: string, age_seconds: ?int}} $report
     */
    private function writeCache(string $cachePath, array $report): void
    {
        $directory = dirname($cachePath);
        if (
            !is_dir($directory)
            &&
            !mkdir($directory, 0o775, true)
            &&
            !is_dir($directory)
        ) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
        }

        file_put_contents($cachePath, json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n");
    }

    /**
     * @param array{generated_at: string, max_age_seconds: int, tools: array<string, array{available: bool, path: ?string}>, agent_map_index: array{present: bool, path: string, age_seconds: ?int}} $report
     */
    private function render(array $report, bool $fromCache, string $cachePath): string
    {
        $lines = [];
        foreach ($report['tools'] as $name => $info) {
            if ($info['available']) {
                $lines[] = InitCheckResult::ok($name . ': available (' . $info['path'] . ')')->render();

                continue;
            }

            // An absent external tool is a legitimate state, not a broken setup:
            // it reports one evidence plane the run will not have.
            $lines[] = array_key_exists($name, self::EXTERNAL_EVIDENCE_TOOLS)
                ? InitCheckResult::info($name . ': not installed (external evidence tool)')->render()
                : InitCheckResult::warn($name . ': not found in PATH')->render();
        }

        $mapIndex = $report['agent_map_index'];
        if ($mapIndex['present']) {
            $ageSeconds = $mapIndex['age_seconds'] ?? 0;
            $lines[] = InitCheckResult::info('agent-map index: present (' . $mapIndex['path'] . ', ' . $this->formatAge($ageSeconds) . ' old)')->render();
        } else {
            $lines[] = InitCheckResult::info('agent-map index: not built (' . $mapIndex['path'] . ')')->render();
        }

        $cacheNote = $fromCache
            ? 'cache: reused (' . $cachePath . ', max-age ' . $this->formatAge($report['max_age_seconds']) . ') -- use --refresh to force a re-probe'
            : 'cache: refreshed (' . $cachePath . ', max-age ' . $this->formatAge($report['max_age_seconds']) . ')';
        $lines[] = InitCheckResult::info($cacheNote)->render();

        return implode("\n", $lines) . "\n";
    }

    private function renderProjectComposerTools(): string
    {
        $requirements = $this->projectComposerToolRequirements();
        if ($requirements === null) {
            return "\n" . InitCheckResult::info(
                'project-integrated PHPStan tools: root composer.json missing or unreadable',
            )->render() . "\n";
        }

        $lines = ["", 'Project-integrated PHPStan tools:'];
        $missing = [];
        foreach (self::PROJECT_COMPOSER_TOOLS as $package => $purpose) {
            $requirement = $requirements[$package] ?? null;
            if ($requirement === null) {
                $lines[] = InitCheckResult::info(
                    $package . ': not configured in root Composer (' . $purpose . ')',
                )->render();
                $missing[] = $package;

                continue;
            }

            $message = $package . ': configured in root Composer ' . $requirement['section']
                . ' (' . $requirement['constraint'] . '; ' . $purpose . ')';
            $lines[] = $requirement['section'] === 'require-dev'
                ? InitCheckResult::ok($message)->render()
                : InitCheckResult::warn($message . '; dev tooling should normally live in require-dev')->render();
        }

        if ($missing !== []) {
            $lines[] = InitCheckResult::info(
                'root Composer install: composer require --dev ' . implode(' ', $missing),
            )->render();
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array<string, array{section: 'require'|'require-dev', constraint: non-empty-string}>|null
     */
    private function projectComposerToolRequirements(): ?array
    {
        $composerPath = rtrim($this->rootPath, '/') . '/composer.json';
        if (!is_file($composerPath)) {
            return null;
        }

        $content = file_get_contents($composerPath);
        if (!is_string($content)) {
            return null;
        }

        $composer = json_decode($content, true);
        if (!is_array($composer)) {
            return null;
        }

        $requirements = [];
        foreach (['require', 'require-dev'] as $section) {
            $sectionRequirements = $composer[$section] ?? null;
            if (!is_array($sectionRequirements)) {
                continue;
            }

            foreach (self::PROJECT_COMPOSER_TOOLS as $package => $_purpose) {
                $constraint = $sectionRequirements[$package] ?? null;
                if (!is_string($constraint)) {
                    continue;
                }

                $constraint = trim($constraint);
                if ($constraint === '') {
                    continue;
                }

                $requirements[$package] = [
                    'section' => $section,
                    'constraint' => $constraint,
                ];
            }
        }

        return $requirements;
    }

    private function formatAge(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return (int) round($seconds / 60) . 'm';
        }

        if ($seconds < 86400) {
            return (int) round($seconds / 3600) . 'h';
        }

        return (int) round($seconds / 86400) . 'd';
    }

    /** An explicit --cache wins; otherwise the layout owner decides where the inventory lives. */
    private function resolveCachePath(?string $explicit): string
    {
        return $explicit === null
            ? (new ProjectLayout($this->rootPath))->toolInventory()
            : $this->resolvePath($explicit);
    }

    private function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return rtrim($this->rootPath, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['cache', 'max-age'];
        $flagOptions = ['refresh'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!str_starts_with($token, '--')) {
                return 'Unknown init tools argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');

            if (in_array($normalized, $flagOptions, true)) {
                continue;
            }

            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init tools option: --' . $normalized;
            }

            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init tools option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     */
    private function readMaxAge(array $tokens): int
    {
        $value = OptionTokens::value($tokens, 'max-age');
        if ($value === null || !ctype_digit($value)) {
            return self::DEFAULT_MAX_AGE_SECONDS;
        }

        return (int) $value;
    }

    private function usage(): string
    {
        return <<<'TXT'
        Usage:
          agent-loop init tools [--refresh] [--max-age=SECONDS] [--cache=PATH]

        Probes whether rg, git, php, composer, and docker are reachable in PATH,
        and whether an agent-map index exists, then caches the result so agents
        do not have to re-probe availability at the start of every session.

        Also probes the external evidence tools itp-context and slop-scan, which
        this package does not install: a project-local installation (vendor/bin
        or an isolated tools/ project) is preferred over an ambient PATH build.
        Use the reported path to invoke them; absence is a legitimate state.

        Project-integrated PHPStan extensions are different: they must be direct
        root Composer dependencies so they participate in the same PHPStan process
        as the repository under analysis. Their root configuration is reported
        separately and missing packages get one explicit composer require command.

        Options:
          --refresh       Force a re-probe even if the cache is still fresh.
          --max-age       Cache freshness window in seconds (default 3600).
          --cache         Cache file path (default .agent-loop/tool-inventory.json).
        TXT . "\n";
    }
}
