<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final readonly class InitSyncSkillsCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<string> $tokens
     */
    public function run(array $tokens): int
    {
        $argumentError = $this->validateTokens($tokens);
        if ($argumentError !== null) {
            fwrite(\STDERR, $argumentError . "\n");

            return 1;
        }

        $config = (new InitConfigLoader($this->rootPath))->load($this->readOptionValue($tokens, 'config'));
        foreach ($config['warnings'] as $warning) {
            echo $warning . "\n";
        }

        $agentValue = $this->readOptionValue($tokens, 'agent');
        if ($agentValue === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse($agentValue, ['codex', 'claude', 'copilot', 'antigravity'], true, $config['agents']);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $this->readPathOverrides($tokens));
        $dryRun = $this->hasFlag($tokens, 'dry-run');
        $force = $this->hasFlag($tokens, 'force');

        $agents = $agent->isAll() ? ['codex', 'claude', 'copilot', 'antigravity'] : [$agent->canonicalName()];
        foreach ($agents as $canonicalAgent) {
            $exit = $this->syncAgent($canonicalAgent, $paths, $dryRun, $force);
            if ($exit !== 0) {
                return $exit;
            }
        }

        return 0;
    }

    private function syncAgent(string $agent, AgentAssetSourcePaths $paths, bool $dryRun, bool $force): int
    {
        $skillFiles = $this->findSkillFiles($paths->absoluteSkillsRoot());
        if ($skillFiles === []) {
            echo '[WARN] sync skills: no skills found under ' . $paths->skillsRoot() . '/*/SKILL.md' . "\n";

            return 0;
        }

        $errors = [];
        foreach ($skillFiles as $directoryName => $skillFile) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $directoryName) !== 1 || $directoryName === '.' || $directoryName === '..' || str_starts_with($directoryName, '.')) {
                $errors[] = '[FAIL] sync skills: invalid skill directory name ' . $directoryName;

                continue;
            }

            if (!is_readable($skillFile)) {
                $errors[] = '[FAIL] sync skills: unreadable skill ' . $directoryName;

                continue;
            }

            $content = file_get_contents($skillFile);
            if (!is_string($content) || trim($content) === '') {
                $errors[] = '[FAIL] sync skills: empty skill ' . $directoryName;
            }
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                echo $error . "\n";
            }

            return 1;
        }

        $targetRoot = $this->resolveTargetRoot($agent);
        try {
            $manifest = InitSyncManifest::load($targetRoot, 'skills', $agent);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $desiredEntries = array_keys($skillFiles);
        sort($desiredEntries);

        foreach ($desiredEntries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if (($this->pathExists($targetPath)) && !$manifest->isManaged($entry) && !$force) {
                echo '[FAIL] sync skills: unmanaged target already exists ' . $targetPath . ' (use --force to overwrite)' . "\n";

                return 1;
            }
        }

        foreach ($manifest->staleEntries($desiredEntries) as $staleEntry) {
            $targetPath = $targetRoot . '/' . $staleEntry;
            if ($dryRun) {
                echo '[DRY-RUN] sync skills: remove stale ' . $targetPath . "\n";

                continue;
            }

            $this->removePath($targetPath);
            echo '[OK] sync skills: removed stale ' . $targetPath . "\n";
        }

        foreach ($skillFiles as $entry => $skillFile) {
            $sourceDir = dirname($skillFile);
            $targetDir = $targetRoot . '/' . $entry;

            if ($dryRun) {
                echo '[DRY-RUN] sync skills: install ' . $entry . ' -> ' . $targetDir . "\n";

                continue;
            }

            $this->copyDirectory($sourceDir, $targetDir);
            echo '[OK] sync skills: installed ' . $entry . ' -> ' . $targetDir . "\n";
        }

        if (!$dryRun) {
            if (!is_dir($targetRoot) && !mkdir($targetRoot, 0o775, true) && !is_dir($targetRoot)) {
                fwrite(\STDERR, 'Unable to create target directory: ' . $targetRoot . "\n");

                return 1;
            }

            $manifest->write($desiredEntries);
        }

        echo '[OK] sync skills: synced ' . count($skillFiles) . ' skill file(s) for ' . $agent . ' into ' . $targetRoot . "\n";
        $reloadHint = $this->reloadHint($agent);
        if ($reloadHint !== null) {
            echo $reloadHint . "\n";
        }

        return 0;
    }

    private function resolveTargetRoot(string $agent): string
    {
        return match ($agent) {
            'codex' => $this->resolvePathFromEnv('CODEX_SKILLS_DIR')
                ?? (($codexHome = $this->resolvePathFromEnv('CODEX_HOME')) !== null ? $codexHome . '/skills' : $this->rootPath . '/.codex/skills'),
            'copilot' => $this->resolvePathFromEnv('COPILOT_SKILLS_DIR') ?? $this->rootPath . '/.github/skills',
            'claude' => $this->resolvePathFromEnv('CLAUDE_SKILLS_DIR') ?? $this->rootPath . '/.claude/skills',
            default => $this->resolvePathFromEnv('ANTIGRAVITY_SKILLS_DIR') ?? $this->rootPath . '/.agents/skills',
        };
    }

    private function reloadHint(string $agent): ?string
    {
        return match ($agent) {
            'copilot' => "[INFO] Run '/skills reload' in your active Copilot CLI session if needed.",
            'antigravity' => "[INFO] Run '/skills reload' in your active Antigravity CLI session if needed.",
            default => null,
        };
    }

    private function copyDirectory(string $sourceDir, string $targetDir): void
    {
        if ($this->pathExists($targetDir)) {
            $this->removePath($targetDir);
        }

        if (!mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
            throw new InvalidArgumentException('Unable to create target directory: ' . $targetDir);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen(rtrim($sourceDir, '/')) + 1);
            $destinationPath = $targetDir . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destinationPath) && !mkdir($destinationPath, 0o775, true) && !is_dir($destinationPath)) {
                    throw new InvalidArgumentException('Unable to create target directory: ' . $destinationPath);
                }

                continue;
            }

            $destinationDir = dirname($destinationPath);
            if (!is_dir($destinationDir) && !mkdir($destinationDir, 0o775, true) && !is_dir($destinationDir)) {
                throw new InvalidArgumentException('Unable to create target directory: ' . $destinationDir);
            }

            if (!copy($item->getPathname(), $destinationPath)) {
                throw new InvalidArgumentException('Unable to copy skill file: ' . $item->getPathname());
            }
        }
    }

    private function removePath(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new InvalidArgumentException('Unable to remove file: ' . $path);
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    throw new InvalidArgumentException('Unable to remove directory: ' . $item->getPathname());
                }

                continue;
            }

            if (!unlink($item->getPathname())) {
                throw new InvalidArgumentException('Unable to remove file: ' . $item->getPathname());
            }
        }

        if (!rmdir($path)) {
            throw new InvalidArgumentException('Unable to remove directory: ' . $path);
        }
    }

    private function pathExists(string $path): bool
    {
        return is_file($path) || is_dir($path) || is_link($path);
    }

    private function resolvePathFromEnv(string $envName): ?string
    {
        $value = getenv($envName);
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        if (str_starts_with($value, '/')) {
            return rtrim($value, '/');
        }

        return rtrim($this->rootPath, '/') . '/' . trim($value, '/');
    }

    /**
     * @param list<string> $tokens
     * @return array<string, string>
     */
    private function readPathOverrides(array $tokens): array
    {
        $value = $this->readOptionValue($tokens, 'skills-root');

        return $value === null ? [] : ['skills-root' => $value];
    }

    /**
     * @param list<string> $tokens
     */
    private function hasFlag(array $tokens, string $name): bool
    {
        return in_array('--' . $name, $tokens, true);
    }

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent', 'config', 'skills-root'];
        $flagOptions = ['dry-run', 'force'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init sync-skills argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, array_merge($valueOptions, $flagOptions), true)) {
                return 'Unknown init sync-skills option: --' . $normalized;
            }

            if (in_array($normalized, $valueOptions, true) && !str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init sync-skills option: --' . $normalized;
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
     * @return array<string, string>
     */
    private function findSkillFiles(string $skillsRoot): array
    {
        if (!is_dir($skillsRoot)) {
            return [];
        }

        $files = [];
        foreach (scandir($skillsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $skillFile = $skillsRoot . '/' . $entry . '/SKILL.md';
            if (is_file($skillFile)) {
                $files[$entry] = $skillFile;
            }
        }

        ksort($files);

        return $files;
    }
}
