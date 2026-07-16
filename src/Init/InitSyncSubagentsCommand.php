<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitSyncSubagentsCommand
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
            $agent = InitAgent::parse($agentValue, ['copilot', 'antigravity'], true, $config['agents']);
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
        $adoptExisting = $this->hasFlag($tokens, 'adopt-existing');

        $agents = $agent->isAll() ? ['copilot', 'antigravity'] : [$agent->canonicalName()];
        foreach ($agents as $canonicalAgent) {
            $exit = $this->syncAgent($canonicalAgent, $paths, $dryRun, $force, $adoptExisting);
            if ($exit !== 0) {
                return $exit;
            }
        }

        return 0;
    }

    private function syncAgent(string $agent, AgentAssetSourcePaths $paths, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        $sourceFiles = $this->findSubagentFiles($paths->absoluteSubagentsRoot());
        if ($sourceFiles === []) {
            echo '[WARN] sync subagents: no subagents found under ' . $paths->subagentsRoot() . '/*.md' . "\n";

            return 0;
        }

        $definitions = [];
        foreach ($sourceFiles as $sourceFile) {
            $errors = SubagentDefinition::validationErrors($sourceFile);
            if ($errors !== []) {
                foreach ($errors as $error) {
                    echo '[FAIL] sync subagents: ' . basename($sourceFile) . ': ' . $error . "\n";
                }

                return 1;
            }

            $definitions[$sourceFile] = SubagentDefinition::fromCanonicalFile($sourceFile);
        }

        $targetRoot = $this->resolveTargetRoot($agent);
        try {
            $manifest = InitSyncManifest::load($targetRoot, 'subagents', $agent);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $targetSuffix = $agent === 'copilot' ? '.agent.md' : '.md';
        $desiredEntries = [];
        foreach (array_keys($definitions) as $sourceFile) {
            $desiredEntries[] = basename($sourceFile, '.md') . $targetSuffix;
        }
        sort($desiredEntries);

        $adopted = [];
        foreach ($desiredEntries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if (($this->pathExists($targetPath)) && !$manifest->isManaged($entry) && !$force) {
                if ($adoptExisting) {
                    $adopted[$entry] = true;

                    continue;
                }

                echo '[FAIL] sync subagents: unmanaged target already exists ' . $targetPath . ' (use --force to overwrite, or --adopt-existing to record it as managed without touching its content)' . "\n";

                return 1;
            }
        }

        foreach ($manifest->staleEntries($desiredEntries) as $staleEntry) {
            $targetPath = $targetRoot . '/' . $staleEntry;
            if ($dryRun) {
                echo '[DRY-RUN] sync subagents: remove stale ' . $targetPath . "\n";

                continue;
            }

            $this->removePath($targetPath);
            echo '[OK] sync subagents: removed stale ' . $targetPath . "\n";
        }

        foreach ($definitions as $sourceFile => $definition) {
            $entry = basename($sourceFile, '.md') . $targetSuffix;
            $targetFile = $targetRoot . '/' . $entry;

            if (isset($adopted[$entry])) {
                echo ($dryRun ? '[DRY-RUN] sync subagents: would adopt' : '[OK] sync subagents: adopted') . ' existing ' . $targetFile . ' into the manifest (content left untouched)' . "\n";

                continue;
            }

            if ($dryRun) {
                echo '[DRY-RUN] sync subagents: install ' . basename($targetFile) . ' -> ' . $targetFile . "\n";

                continue;
            }

            $this->writeFile($targetFile, $definition->renderForClient($agent) . "\n");
            echo '[OK] sync subagents: installed ' . basename($targetFile) . ' -> ' . $targetFile . "\n";
        }

        if (!$dryRun) {
            if (!is_dir($targetRoot) && !mkdir($targetRoot, 0o775, true) && !is_dir($targetRoot)) {
                fwrite(\STDERR, 'Unable to create target directory: ' . $targetRoot . "\n");

                return 1;
            }

            $manifest->write($desiredEntries);
        }

        echo '[OK] sync subagents: synced ' . count($definitions) . ' subagent file(s) for ' . $agent . ' into ' . $targetRoot . "\n";
        $reloadHint = $agent === 'antigravity'
            ? "[INFO] Run '/agents reload' in your active Antigravity CLI session if needed."
            : '[INFO] Reload the active Copilot agent registry if needed.';
        echo $reloadHint . "\n";

        return 0;
    }

    private function resolveTargetRoot(string $agent): string
    {
        return $agent === 'copilot'
            ? ($this->resolvePathFromEnv('COPILOT_AGENTS_DIR') ?? $this->rootPath . '/.github/agents')
            : ($this->resolvePathFromEnv('ANTIGRAVITY_AGENTS_DIR') ?? $this->rootPath . '/.agents/agents');
    }

    private function writeFile(string $filePath, string $content): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create target directory: ' . $directory);
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new InvalidArgumentException('Unable to write subagent file: ' . $filePath);
        }
    }

    private function removePath(string $path): void
    {
        if (!is_file($path) && !is_link($path)) {
            return;
        }

        if (!unlink($path)) {
            throw new InvalidArgumentException('Unable to remove file: ' . $path);
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

        if ($this->isAbsolutePath($value)) {
            return rtrim(str_replace('\\', '/', $value), '/');
        }

        return rtrim(str_replace('\\', '/', $this->rootPath), '/') . '/' . trim(str_replace('\\', '/', $value), '/');
    }

    private function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/')) {
            return true;
        }

        if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1) {
            return true;
        }

        if (str_starts_with($path, '\\\\') || str_starts_with($path, '//')) {
            return true;
        }

        return false;
    }


    /**
     * @param list<string> $tokens
     * @return array<string, string>
     */
    private function readPathOverrides(array $tokens): array
    {
        $value = $this->readOptionValue($tokens, 'subagents-root');

        return $value === null ? [] : ['subagents-root' => $value];
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
        $valueOptions = ['agent', 'config', 'subagents-root'];
        $flagOptions = ['dry-run', 'force', 'adopt-existing'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init sync-subagents argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, array_merge($valueOptions, $flagOptions), true)) {
                return 'Unknown init sync-subagents option: --' . $normalized;
            }

            if (in_array($normalized, $valueOptions, true) && !str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init sync-subagents option: --' . $normalized;
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
     * @return list<string>
     */
    private function findSubagentFiles(string $subagentsRoot): array
    {
        if (!is_dir($subagentsRoot)) {
            return [];
        }

        $files = [];
        foreach (scandir($subagentsRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                continue;
            }

            $path = $subagentsRoot . '/' . $entry;
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }
}
