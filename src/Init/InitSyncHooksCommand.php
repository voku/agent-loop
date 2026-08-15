<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\PathResolver;

final readonly class InitSyncHooksCommand
{
    /** Manifest entry for the one `settings.json` key this sync owns on the Claude Code target. */
    private const string CLAUDE_SETTINGS_HOOKS_ENTRY = 'settings.json#hooks';

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

        $config = (new InitConfigLoader($this->rootPath))->load(OptionTokens::value($tokens, 'config'));
        foreach ($config['warnings'] as $warning) {
            echo $warning . "\n";
        }

        $agentValue = OptionTokens::value($tokens, 'agent');
        if ($agentValue === null) {
            fwrite(\STDERR, "Missing required option: --agent\n");

            return 1;
        }

        try {
            $agent = InitAgent::parse($agentValue, ['codex', 'claude'], false, $config['agents']);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $overrides = $this->readPathOverrides($tokens);
        if ($agent->canonicalName() === 'claude' && isset($overrides['hooks-root'])) {
            $overrides['claude-hooks-root'] = $overrides['hooks-root'];
            unset($overrides['hooks-root']);
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $overrides);
        $dryRun = OptionTokens::hasFlag($tokens, 'dry-run');
        $force = OptionTokens::hasFlag($tokens, 'force');
        $adoptExisting = OptionTokens::hasFlag($tokens, 'adopt-existing');

        if ($agent->canonicalName() === 'claude') {
            return $this->syncClaudeHooks($paths, $dryRun, $force, $adoptExisting);
        }

        return $this->syncHooks($paths, $dryRun, $force, $adoptExisting);
    }

    /**
     * Claude Code registers hooks inside `settings.json`, so the bundle's scripts are
     * installed like any other client asset while the registration itself is merged
     * into that single settings key.
     */
    private function syncClaudeHooks(AgentAssetSourcePaths $paths, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        $hooksRoot = $paths->absoluteClaudeHooksRoot();
        $errors = ClaudeHooksDefinition::validationErrors($hooksRoot);
        if ($errors === [] && !is_file($hooksRoot . '/hooks.json') && !is_dir($hooksRoot . '/hooks')) {
            echo '[WARN] sync hooks: no hooks found under ' . $paths->claudeHooksRoot() . "\n";

            return 0;
        }

        if ($errors !== []) {
            foreach ($errors as $error) {
                echo '[FAIL] sync hooks: ' . $error . "\n";
            }

            return 1;
        }

        try {
            $definition = ClaudeHooksDefinition::fromRoot($hooksRoot);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $targetRoot = $this->resolveClaudeTargetRoot();
        $settingsPath = $targetRoot . '/settings.json';
        $settings = new ClaudeSettingsHooksWriter($settingsPath);

        try {
            $manifest = InitSyncManifest::load($targetRoot, 'hooks', 'claude');
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $desiredEntries = [self::CLAUDE_SETTINGS_HOOKS_ENTRY];
        $projectionSources = [
            self::CLAUDE_SETTINGS_HOOKS_ENTRY => ManagedAssetSource::fromPath(
                $this->rootPath,
                $hooksRoot . '/hooks.json',
                'hooks:claude:settings-hooks',
            ),
        ];
        foreach ($definition->scriptNames() as $scriptName) {
            $entry = 'hooks/' . $scriptName;
            $desiredEntries[] = $entry;
            $projectionSources[$entry] = ManagedAssetSource::fromPath(
                $this->rootPath,
                $hooksRoot . '/' . $entry,
                'hooks:claude:' . $entry,
            );
        }
        sort($desiredEntries);

        $adopted = [];
        foreach ($desiredEntries as $entry) {
            $exists = $entry === self::CLAUDE_SETTINGS_HOOKS_ENTRY
                ? $settings->hasHooks()
                : $this->pathExists($targetRoot . '/' . $entry);
            if (!$exists || $manifest->isManaged($entry) || $force) {
                continue;
            }

            if ($adoptExisting) {
                $adopted[$entry] = true;

                continue;
            }

            $describedTarget = $entry === self::CLAUDE_SETTINGS_HOOKS_ENTRY
                ? $settingsPath . ' (hooks key)'
                : $targetRoot . '/' . $entry;

            echo '[FAIL] sync hooks: unmanaged target already exists ' . $describedTarget . ' (use --force to overwrite, or --adopt-existing to record it as managed without touching its content)' . "\n";

            return 1;
        }

        foreach ($manifest->staleEntries($desiredEntries) as $staleEntry) {
            if ($staleEntry === self::CLAUDE_SETTINGS_HOOKS_ENTRY) {
                if ($dryRun) {
                    echo '[DRY-RUN] sync hooks: remove hooks key from ' . $settingsPath . "\n";

                    continue;
                }

                $settings->removeHooks();
                echo '[OK] sync hooks: removed hooks key from ' . $settingsPath . "\n";

                continue;
            }

            $targetPath = $targetRoot . '/' . $staleEntry;
            if ($dryRun) {
                echo '[DRY-RUN] sync hooks: remove stale ' . $targetPath . "\n";

                continue;
            }

            $this->removePath($targetPath);
            echo '[OK] sync hooks: removed stale ' . $targetPath . "\n";
        }

        foreach ($definition->scriptNames() as $scriptName) {
            $entry = 'hooks/' . $scriptName;
            $targetFile = $targetRoot . '/' . $entry;

            if (isset($adopted[$entry])) {
                echo '[OK] sync hooks: adopted existing ' . $targetFile . ' into the manifest (content left untouched)' . "\n";

                continue;
            }

            if ($dryRun) {
                echo '[DRY-RUN] sync hooks: install ' . $scriptName . ' -> ' . $targetFile . "\n";

                continue;
            }

            $sourceFile = $hooksRoot . '/hooks/' . $scriptName;
            $content = file_get_contents($sourceFile);
            if (!is_string($content)) {
                fwrite(\STDERR, 'Unable to read hook script: ' . $sourceFile . "\n");

                return 1;
            }

            $this->writeFile($targetFile, $content);
            echo '[OK] sync hooks: installed ' . $scriptName . ' -> ' . $targetFile . "\n";
        }

        if (isset($adopted[self::CLAUDE_SETTINGS_HOOKS_ENTRY])) {
            echo '[OK] sync hooks: adopted existing hooks key in ' . $settingsPath . ' into the manifest (content left untouched)' . "\n";
        } elseif ($dryRun) {
            echo '[DRY-RUN] sync hooks: write hooks key -> ' . $settingsPath . "\n";
        } else {
            $settings->write($definition->hooksObject());
            echo '[OK] sync hooks: wrote hooks key -> ' . $settingsPath . "\n";
        }

        if (!$dryRun) {
            $manifest->writeProjections(
                $projectionSources,
                $this->hookCapabilities(),
                array_keys($adopted),
            );
        }

        echo '[OK] sync hooks: synced ' . count($definition->scriptNames()) . ' hook file(s) into ' . $targetRoot . "\n";
        echo "[IMPORTANT] Open '/hooks' in Claude Code to review the registered repository-local hooks; every other key in settings.json was left untouched.\n";

        return 0;
    }

    private function resolveClaudeTargetRoot(): string
    {
        return PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_CONFIG_DIR') ?? $this->rootPath . '/.claude';
    }

    private function syncHooks(AgentAssetSourcePaths $paths, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        $hooksRoot = $paths->absoluteHooksRoot();
        $errors = CodexHooksDefinition::validationErrors($hooksRoot);
        if ($errors === [] && !is_file($hooksRoot . '/hooks.json') && !is_dir($hooksRoot . '/hooks')) {
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
            $definition = CodexHooksDefinition::fromRoot($hooksRoot);
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
        $projectionSources = [
            'hooks.json' => ManagedAssetSource::fromPath(
                $this->rootPath,
                $hooksRoot . '/hooks.json',
                'hooks:codex:hooks.json',
            ),
        ];
        foreach ($definition->scriptNames() as $scriptName) {
            $entry = 'hooks/' . $scriptName;
            $desiredEntries[] = $entry;
            $projectionSources[$entry] = ManagedAssetSource::fromPath(
                $this->rootPath,
                $hooksRoot . '/' . $entry,
                'hooks:codex:' . $entry,
            );
        }
        sort($desiredEntries);

        $adopted = [];
        foreach ($desiredEntries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if ($this->pathExists($targetPath) && !$manifest->isManaged($entry) && !$force) {
                if ($adoptExisting) {
                    $adopted[$entry] = true;

                    continue;
                }

                echo '[FAIL] sync hooks: unmanaged target already exists ' . $targetPath . ' (use --force to overwrite, or --adopt-existing to record it as managed without touching its content)' . "\n";

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
            echo '[DRY-RUN] sync hooks: ' . (isset($adopted['hooks.json']) ? 'adopt' : 'install') . ' hooks.json -> ' . $targetRoot . '/hooks.json' . "\n";
            foreach ($definition->scriptNames() as $scriptName) {
                $verb = isset($adopted['hooks/' . $scriptName]) ? 'adopt' : 'install';
                echo '[DRY-RUN] sync hooks: ' . $verb . ' ' . $scriptName . ' -> ' . $targetRoot . '/hooks/' . $scriptName . "\n";
            }
        } else {
            if (isset($adopted['hooks.json'])) {
                echo '[OK] sync hooks: adopted existing ' . $targetRoot . '/hooks.json into the manifest (content left untouched)' . "\n";
            } else {
                $this->writeFile($targetRoot . '/hooks.json', $definition->hooksJsonContent());
                echo '[OK] sync hooks: installed hooks.json -> ' . $targetRoot . '/hooks.json' . "\n";
            }

            foreach ($definition->scriptNames() as $scriptName) {
                $entry = 'hooks/' . $scriptName;
                $targetFile = $targetRoot . '/' . $entry;

                if (isset($adopted[$entry])) {
                    echo '[OK] sync hooks: adopted existing ' . $targetFile . ' into the manifest (content left untouched)' . "\n";

                    continue;
                }

                $sourceFile = $hooksRoot . '/hooks/' . $scriptName;
                $content = file_get_contents($sourceFile);
                if (!is_string($content)) {
                    fwrite(\STDERR, 'Unable to read hook script: ' . $sourceFile . "\n");

                    return 1;
                }

                $this->writeFile($targetFile, $content);
                echo '[OK] sync hooks: installed ' . $scriptName . ' -> ' . $targetFile . "\n";
            }

            $manifest->writeProjections(
                $projectionSources,
                $this->hookCapabilities(),
                array_keys($adopted),
            );
        }

        echo '[OK] sync hooks: synced ' . count($definition->scriptNames()) . ' hook file(s) into ' . $targetRoot . "\n";
        echo "[IMPORTANT] Open '/hooks' in Codex to review and trust the updated repository-local hooks.\n";

        return 0;
    }

    /** @return list<HostCapability> */
    private function hookCapabilities(): array
    {
        return [
            HostCapability::SessionBootstrap,
            HostCapability::SubagentBootstrap,
            HostCapability::PreToolGuardrail,
        ];
    }

    private function resolveTargetRoot(): string
    {
        return PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME') ?? $this->rootPath . '/.codex';
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
        $value = OptionTokens::value($tokens, 'hooks-root');

        return $value === null ? [] : ['hooks-root' => $value];
    }

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent', 'config', 'hooks-root'];
        $flagOptions = ['dry-run', 'force', 'adopt-existing'];
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
}
