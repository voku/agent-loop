<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;

final readonly class InitSyncHooksCommand
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
            $agent = InitAgent::parse($agentValue, ['codex'], false, $config['agents']);
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

        return $this->syncHooks($paths, $dryRun, $force);
    }

    private function syncHooks(AgentAssetSourcePaths $paths, bool $dryRun, bool $force): int
    {
        $errors = CodexHooksDefinition::validationErrors($paths->absoluteHooksRoot());
        if ($errors === [] && !is_file($paths->absoluteHooksRoot() . '/hooks.json') && !is_dir($paths->absoluteHooksRoot() . '/hooks')) {
            echo '[WARN] sync hooks: no hooks found under ' . $paths->hooksRoot() . "\n";

            return 0;
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                echo '[FAIL] sync hooks: ' . $error . "\n";
            }

            return 1;
        }

        try {
            $definition = CodexHooksDefinition::fromRoot($paths->absoluteHooksRoot());
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $targetRoot = $this->resolveTargetRoot();
        try {
            $manifest = InitSyncManifest::load($targetRoot, 'hooks', 'codex');
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $desiredEntries = ['hooks.json'];
        foreach ($definition->scriptNames() as $scriptName) {
            $desiredEntries[] = 'hooks/' . $scriptName;
        }
        sort($desiredEntries);

        foreach ($desiredEntries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if ($this->pathExists($targetPath) && !$manifest->isManaged($entry) && !$force) {
                echo '[FAIL] sync hooks: unmanaged target already exists ' . $targetPath . ' (use --force to overwrite)' . "\n";

                return 1;
            }
        }

        foreach ($manifest->staleEntries($desiredEntries) as $staleEntry) {
            $targetPath = $targetRoot . '/' . $staleEntry;
            if ($dryRun) {
                echo '[DRY-RUN] sync hooks: remove stale ' . $targetPath . "\n";

                continue;
            }

            $this->removePath($targetPath);
            echo '[OK] sync hooks: removed stale ' . $targetPath . "\n";
        }

        if ($dryRun) {
            echo '[DRY-RUN] sync hooks: install hooks.json -> ' . $targetRoot . '/hooks.json' . "\n";
            foreach ($definition->scriptNames() as $scriptName) {
                echo '[DRY-RUN] sync hooks: install ' . $scriptName . ' -> ' . $targetRoot . '/hooks/' . $scriptName . "\n";
            }
        } else {
            $this->writeFile($targetRoot . '/hooks.json', $definition->hooksJsonContent());
            echo '[OK] sync hooks: installed hooks.json -> ' . $targetRoot . '/hooks.json' . "\n";

            foreach ($definition->scriptNames() as $scriptName) {
                $sourceFile = $paths->absoluteHooksRoot() . '/hooks/' . $scriptName;
                $targetFile = $targetRoot . '/hooks/' . $scriptName;
                $content = file_get_contents($sourceFile);
                if (!is_string($content)) {
                    fwrite(\STDERR, 'Unable to read hook script: ' . $sourceFile . "\n");

                    return 1;
                }

                $this->writeFile($targetFile, $content);
                echo '[OK] sync hooks: installed ' . $scriptName . ' -> ' . $targetFile . "\n";
            }

            $manifest->write($desiredEntries);
        }

        echo '[OK] sync hooks: synced ' . count($definition->scriptNames()) . ' hook file(s) into ' . $targetRoot . "\n";
        echo "[IMPORTANT] Open '/hooks' in Codex to review and trust the updated repository-local hooks.\n";

        return 0;
    }

    private function resolveTargetRoot(): string
    {
        $codexHome = getenv('CODEX_HOME');
        if (is_string($codexHome) && trim($codexHome) !== '') {
            if (str_starts_with($codexHome, '/')) {
                return rtrim($codexHome, '/');
            }

            return rtrim($this->rootPath, '/') . '/' . trim($codexHome, '/');
        }

        return $this->rootPath . '/.codex';
    }

    private function writeFile(string $filePath, string $content): void
    {
        $directory = dirname($filePath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new InvalidArgumentException('Unable to create target directory: ' . $directory);
        }

        if (file_put_contents($filePath, $content) === false) {
            throw new InvalidArgumentException('Unable to write file: ' . $filePath);
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

        foreach (array_values(array_diff(scandir($path) ?: [], ['.', '..'])) as $entry) {
            $entryPath = $path . '/' . $entry;
            $this->removePath($entryPath);
        }

        if (!rmdir($path)) {
            throw new InvalidArgumentException('Unable to remove directory: ' . $path);
        }
    }

    private function pathExists(string $path): bool
    {
        return is_file($path) || is_dir($path) || is_link($path);
    }

    /**
     * @param list<string> $tokens
     * @return array<string, string>
     */
    private function readPathOverrides(array $tokens): array
    {
        $value = $this->readOptionValue($tokens, 'hooks-root');

        return $value === null ? [] : ['hooks-root' => $value];
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
        $valueOptions = ['agent', 'config', 'hooks-root'];
        $flagOptions = ['dry-run', 'force'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init sync-hooks argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, array_merge($valueOptions, $flagOptions), true)) {
                return 'Unknown init sync-hooks option: --' . $normalized;
            }

            if (in_array($normalized, $valueOptions, true) && !str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init sync-hooks option: --' . $normalized;
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
}
