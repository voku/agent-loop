<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\PathResolver;

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
            $agent = InitAgent::parse($agentValue, InitAgent::canonicalNames(), true, $config['agents']);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        foreach ($agent->messages() as $message) {
            echo $message . "\n";
        }

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $this->readPathOverrides($tokens));
        $dryRun = OptionTokens::hasFlag($tokens, 'dry-run');
        $force = OptionTokens::hasFlag($tokens, 'force');
        $adoptExisting = OptionTokens::hasFlag($tokens, 'adopt-existing');

        $agents = $agent->isAll() ? InitAgent::canonicalNames() : [$agent->canonicalName()];
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

        $targetSuffix = match ($agent) {
            'codex' => '.toml',
            'copilot' => '.agent.md',
            default => '.md',
        };
        $desiredEntries = [];
        $projectionSources = [];
        foreach (array_keys($definitions) as $sourceFile) {
            $name = basename($sourceFile, '.md');
            $entry = $name . $targetSuffix;
            $desiredEntries[] = $entry;
            $projectionSources[$entry] = ManagedAssetSource::fromPath(
                $this->rootPath,
                $sourceFile,
                'subagent:' . $name,
            );
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

        $cliPath = (new RepositoryActivation($this->rootPath))->cliPath();
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

            $rendered = str_replace('vendor/bin/agent-loop', $cliPath, $definition->renderForClient($agent));
            $this->writeFile($targetFile, $rendered . "\n");
            echo '[OK] sync subagents: installed ' . basename($targetFile) . ' -> ' . $targetFile . "\n";
        }

        if (!$dryRun) {
            if (!is_dir($targetRoot) && !mkdir($targetRoot, 0o775, true) && !is_dir($targetRoot)) {
                fwrite(\STDERR, 'Unable to create target directory: ' . $targetRoot . "\n");

                return 1;
            }

            $manifest->writeProjections(
                $projectionSources,
                [HostCapability::SubagentProjection],
                array_keys($adopted),
            );
        }

        echo '[OK] sync subagents: synced ' . count($definitions) . ' subagent file(s) for ' . $agent . ' into ' . $targetRoot . "\n";
        echo $this->reloadHint($agent) . "\n";

        return 0;
    }

    private function resolveTargetRoot(string $agent): string
    {
        return match ($agent) {
            'codex' => PathResolver::fromEnvironment($this->rootPath, 'CODEX_AGENTS_DIR')
                ?? (($codexHome = PathResolver::fromEnvironment($this->rootPath, 'CODEX_HOME')) !== null ? $codexHome . '/agents' : $this->rootPath . '/.codex/agents'),
            'claude' => PathResolver::fromEnvironment($this->rootPath, 'CLAUDE_AGENTS_DIR') ?? $this->rootPath . '/.claude/agents',
            'opencode' => PathResolver::fromEnvironment($this->rootPath, 'OPENCODE_AGENTS_DIR') ?? $this->rootPath . '/.opencode/agents',
            'copilot' => PathResolver::fromEnvironment($this->rootPath, 'COPILOT_AGENTS_DIR') ?? $this->rootPath . '/.github/agents',
            'gemini' => PathResolver::fromEnvironment($this->rootPath, 'GEMINI_AGENTS_DIR') ?? $this->rootPath . '/.gemini/agents',
            'antigravity' => PathResolver::fromEnvironment($this->rootPath, 'ANTIGRAVITY_AGENTS_DIR') ?? $this->rootPath . '/.agents/agents',
            default => throw new InvalidArgumentException('Unsupported subagent sync target: ' . $agent),
        };
    }

    private function reloadHint(string $agent): string
    {
        return match ($agent) {
            'codex' => '[INFO] Start a fresh Codex session if the project agent registry needs to be reloaded.',
            'claude' => '[INFO] Start a fresh Claude Code session so the project agent registry is re-read.',
            'opencode' => '[INFO] Start a fresh OpenCode session so the project agent registry is re-read.',
            'gemini' => '[INFO] Start a fresh Gemini CLI session so the project agent registry is re-read.',
            'antigravity' => "[INFO] Run '/agents reload' in your active Antigravity CLI session if needed.",
            'copilot' => '[INFO] Reload the active Copilot agent registry if needed.',
            default => throw new InvalidArgumentException('Unsupported subagent sync target: ' . $agent),
        };
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

    /**
     * @param list<string> $tokens
     * @return array<string, string>
     */
    private function readPathOverrides(array $tokens): array
    {
        $value = OptionTokens::value($tokens, 'subagents-root');

        return $value === null ? [] : ['subagents-root' => $value];
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
