<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

/**
 * Probes whether common CLI tools are reachable in PATH and caches the result,
 * so agents do not have to re-probe availability at the start of every session.
 *
 * This is intentionally separate from InitDoctorCommand/InitStatusCommand: those
 * are read-only diagnostics of repo-managed agent assets and never write files.
 * InitToolsCommand's whole purpose is to write a small, gitignored cache file.
 */
final readonly class InitToolsCommand
{
    /**
     * @var list<string>
     */
    private const array KNOWN_TOOLS = ['rg', 'git', 'php', 'composer', 'docker'];

    private const string DEFAULT_CACHE_PATH = '.agent-loop/tool-inventory.json';

    private const int DEFAULT_MAX_AGE_SECONDS = 3600;

    private const string DEFAULT_MAP_INDEX_PATH = '.agent-map/php-symbols.json';

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

        $cachePath = $this->resolvePath($this->readOptionValue($tokens, 'cache') ?? self::DEFAULT_CACHE_PATH);
        $maxAge = $this->readMaxAge($tokens);
        $refresh = in_array('--refresh', $tokens, true);

        $cached = $this->readCache($cachePath);
        $useCache = !$refresh && $cached !== null && $this->isFresh($cached, $maxAge);

        $report = $useCache ? $cached : $this->probe($maxAge);
        if (!$useCache) {
            $this->writeCache($cachePath, $report);
        }

        echo "agent-loop init tools\n\n";
        echo $this->render($report, $useCache, $cachePath);

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

        $mapIndexPath = self::DEFAULT_MAP_INDEX_PATH;
        $absoluteMapIndexPath = $this->resolvePath($mapIndexPath);
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
            $lines[] = $info['available']
                ? InitCheckResult::ok($name . ': available (' . $info['path'] . ')')->render()
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
    private function readOptionValue(array $tokens, string $name): ?string
    {
        $prefix = '--' . $name . '=';
        foreach ($tokens as $index => $token) {
            if (str_starts_with($token, $prefix)) {
                $value = substr($token, strlen($prefix));

                return $value === '' ? null : $value;
            }

            if ($token === '--' . $name) {
                $candidate = $tokens[$index + 1] ?? null;
                if (is_string($candidate) && !str_starts_with($candidate, '--')) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     */
    private function readMaxAge(array $tokens): int
    {
        $value = $this->readOptionValue($tokens, 'max-age');
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

        Options:
          --refresh       Force a re-probe even if the cache is still fresh.
          --max-age       Cache freshness window in seconds (default 3600).
          --cache         Cache file path (default .agent-loop/tool-inventory.json).
        TXT . "\n";
    }
}
