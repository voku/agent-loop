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

        $paths = AgentAssetSourcePaths::fromConfig($this->rootPath, $config);
        $dryRun = OptionTokens::hasFlag($tokens, 'dry-run');
        $force = OptionTokens::hasFlag($tokens, 'force');
        $adoptExisting = OptionTokens::hasFlag($tokens, 'adopt-existing');
        $agents = $agent->isAll() ? InitAgent::canonicalNames() : [$agent->canonicalName()];

        $requestedSubagentRoots = OptionTokens::values($tokens, 'subagents-root');
        if ($requestedSubagentRoots !== []) {
            $subagentRoots = array_values(array_unique(array_map(
                fn (string $path): string => PathResolver::join($this->rootPath, $path),
                $requestedSubagentRoots,
            )));
            $collected = $this->findSubagentFiles($subagentRoots);
            if ($collected['errors'] !== []) {
                foreach ($collected['errors'] as $error) {
                    echo $error . "\n";
                }

                return 1;
            }

            // Explicit roots are root-exact: they are both what this command
            // copies and everything it keeps.
            $sources = [];
            foreach ($collected['files'] as $sourceFile) {
                $name = basename($sourceFile, '.md');
                $sources[$name] = new ManagedSubagentSource(
                    $name,
                    $sourceFile,
                    ManagedAssetSource::fromPath($this->rootPath, $sourceFile, 'subagent:' . $name),
                );
            }

            return $this->syncAgents($agents, $sources, array_keys($sources), $subagentRoots, $dryRun, $force, $adoptExisting);
        }

        // Default mode: the owner resolver computes the desired set for this
        // config. Only the configured project root is materialized here; the
        // rest of the desired set is retained, never pruned and never restored.
        try {
            $desired = (new ManagedSubagentSourceResolver($this->rootPath))->resolve($paths);
        } catch (InvalidArgumentException $exception) {
            echo '[FAIL] sync subagents: ' . $exception->getMessage() . "\n";

            return 1;
        }

        $materialized = array_filter(
            $desired,
            static fn (ManagedSubagentSource $source): bool => $paths->containsSubagentSource($source->path),
        );

        return $this->syncAgents(
            $agents,
            $materialized,
            array_keys($desired),
            [$paths->absoluteSubagentsRoot()],
            $dryRun,
            $force,
            $adoptExisting,
        );
    }

    /**
     * Projects an owner-resolved subagent selection in full, as `install-assets` does.
     *
     * @param list<string> $agents canonical agent names
     * @param array<string, ManagedSubagentSource> $sources subagent-name => source
     * @param list<string> $sourceRoots roots reported when nothing is found
     */
    public function syncResolved(array $agents, array $sources, array $sourceRoots, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        return $this->syncAgents($agents, $sources, array_keys($sources), $sourceRoots, $dryRun, $force, $adoptExisting);
    }

    /**
     * @param list<string> $agents
     * @param array<string, ManagedSubagentSource> $sources entries this command materializes
     * @param list<string> $retainedNames the desired set; managed entries outside it are pruned
     * @param list<string> $sourceRoots
     */
    private function syncAgents(array $agents, array $sources, array $retainedNames, array $sourceRoots, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        foreach ($agents as $agent) {
            $exit = $this->syncAgent($agent, $sources, $retainedNames, $sourceRoots, $dryRun, $force, $adoptExisting);
            if ($exit !== 0) {
                return $exit;
            }
        }

        return 0;
    }

    /**
     * @param array<string, ManagedSubagentSource> $sources
     * @param list<string> $retainedNames
     * @param list<string> $sourceRoots
     */
    private function syncAgent(string $agent, array $sources, array $retainedNames, array $sourceRoots, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        if ($sources === []) {
            echo '[WARN] sync subagents: no subagents found under ' . implode(', ', array_map($this->displayPath(...), $sourceRoots)) . "\n";

            return 0;
        }

        $definitions = [];
        foreach ($sources as $name => $source) {
            $errors = SubagentDefinition::validationErrors($source->path);
            if ($errors !== []) {
                foreach ($errors as $error) {
                    echo '[FAIL] sync subagents: ' . basename($source->path) . ': ' . $error . "\n";
                }

                return 1;
            }

            $definitions[$name] = SubagentDefinition::fromCanonicalFile($source->path);
        }

        $targets = new ManagedAssetTargetCatalog($this->rootPath);
        $targetRoot = $targets->subagentsTargetRoot($agent);
        try {
            $manifest = InitSyncManifest::load($targetRoot, 'subagents', $agent);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $targetSuffix = $targets->subagentSuffix($agent);
        $desiredEntries = [];
        $projectionSources = [];
        foreach ($sources as $name => $source) {
            $entry = $name . $targetSuffix;
            $desiredEntries[] = $entry;
            $projectionSources[$entry] = $source->assetSource;
        }
        sort($desiredEntries);
        $retainedEntries = $desiredEntries;
        foreach ($retainedNames as $retainedName) {
            $retainedEntries[] = $retainedName . $targetSuffix;
        }
        $retainedEntries = array_values(array_unique($retainedEntries));
        sort($retainedEntries);

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

        foreach ($manifest->staleEntries($retainedEntries) as $staleEntry) {
            $targetPath = $targetRoot . '/' . $staleEntry;
            if ($dryRun) {
                echo '[DRY-RUN] sync subagents: remove stale ' . $targetPath . "\n";

                continue;
            }

            $this->removePath($targetPath);
            echo '[OK] sync subagents: removed stale ' . $targetPath . "\n";
        }

        $cliPath = (new RepositoryActivation($this->rootPath))->cliPath();
        foreach ($definitions as $name => $definition) {
            $entry = $name . $targetSuffix;
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
                $retainedEntries,
            );
        }

        echo '[OK] sync subagents: synced ' . count($definitions) . ' subagent file(s) for ' . $agent . ' into ' . $targetRoot . "\n";
        echo $this->reloadHint($agent) . "\n";

        return 0;
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
            'cursor' => '[INFO] Start a fresh Cursor agent session so the project agent registry is re-read.',
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

    private function displayPath(string $path): string
    {
        return PathResolver::relativeTo($this->rootPath, $path);
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
     * @param non-empty-list<string> $subagentRoots
     * @return array{files: array<string, string>, errors: list<string>}
     */
    private function findSubagentFiles(array $subagentRoots): array
    {
        $files = [];
        $sources = [];
        $errors = [];

        foreach ($subagentRoots as $subagentsRoot) {
            if (!is_dir($subagentsRoot)) {
                continue;
            }

            $entries = scandir($subagentsRoot);
            if ($entries === false) {
                $errors[] = '[FAIL] sync subagents: unable to read source root: ' . $this->displayPath($subagentsRoot);

                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || !str_ends_with($entry, '.md')) {
                    continue;
                }

                $path = $subagentsRoot . '/' . $entry;
                if (!is_file($path)) {
                    continue;
                }

                if (isset($files[$entry])) {
                    $errors[] = '[FAIL] sync subagents: duplicate subagent file ' . $entry . ' from ' . $sources[$entry] . ' and ' . $this->displayPath($path);

                    continue;
                }

                $files[$entry] = $path;
                $sources[$entry] = $this->displayPath($path);
            }
        }

        ksort($files);

        return ['files' => $files, 'errors' => $errors];
    }
}
