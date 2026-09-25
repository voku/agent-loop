<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\PathResolver;

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

        $requestedSkillRoots = OptionTokens::values($tokens, 'skills-root');
        if ($requestedSkillRoots !== []) {
            $skillRoots = array_values(array_unique(array_map(
                fn (string $path): string => PathResolver::join($this->rootPath, $path),
                $requestedSkillRoots,
            )));
            foreach ($skillRoots as $skillRoot) {
                if (!is_dir($skillRoot)) {
                    echo '[FAIL] sync skills: source root does not exist: ' . $this->displayPath($skillRoot) . "\n";

                    return 1;
                }
            }

            $collected = $this->collectSkillFiles($skillRoots);
            if ($collected['errors'] !== []) {
                foreach ($collected['errors'] as $error) {
                    echo $error . "\n";
                }

                return 1;
            }

            // Explicit roots are root-exact: they are both what this command
            // copies and everything it keeps.
            $sources = [];
            foreach ($collected['files'] as $entry => $skillFile) {
                $sources[$entry] = ManagedAssetSource::fromPath($this->rootPath, dirname($skillFile), 'skill:' . $entry);
            }

            return $this->syncAgents($agents, $sources, array_keys($sources), $skillRoots, $dryRun, $force, $adoptExisting);
        }

        // Default mode: the owner resolver computes the desired set for this
        // config. Only the configured project root is materialized here; the
        // rest of the desired set - package copies install-assets projected - is
        // retained, never pruned and never restored.
        try {
            $desired = (new ManagedSkillSourceResolver($this->rootPath))->resolve($paths);
        } catch (InvalidArgumentException $exception) {
            echo '[FAIL] sync skills: ' . $exception->getMessage() . "\n";

            return 1;
        }

        $materialized = array_filter(
            $desired,
            static fn (ManagedAssetSource $source): bool => $paths->containsSkillSource($source->path),
        );

        return $this->syncAgents(
            $agents,
            $materialized,
            array_keys($desired),
            [$paths->absoluteSkillsRoot()],
            $dryRun,
            $force,
            $adoptExisting,
        );
    }

    /**
     * Projects an owner-resolved skill selection in full.
     *
     * `install-assets` hands over the resolved desired set instead of source
     * roots, so what it installs is exactly what status, doctor and host-status
     * expect for the same config.
     *
     * @param list<string> $agents canonical agent names
     * @param array<string, ManagedAssetSource> $sources skill-id => source
     * @param list<string> $sourceRoots roots reported in the summary line
     */
    public function syncResolved(array $agents, array $sources, array $sourceRoots, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        return $this->syncAgents($agents, $sources, array_keys($sources), $sourceRoots, $dryRun, $force, $adoptExisting);
    }

    /**
     * @param list<string> $agents
     * @param array<string, ManagedAssetSource> $sources entries this command materializes
     * @param list<string> $retainedEntries the desired set; managed entries outside it are pruned
     * @param list<string> $sourceRoots
     */
    private function syncAgents(array $agents, array $sources, array $retainedEntries, array $sourceRoots, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        foreach ($agents as $agent) {
            $exit = $this->syncAgent($agent, $sources, $retainedEntries, $sourceRoots, $dryRun, $force, $adoptExisting);
            if ($exit !== 0) {
                return $exit;
            }
        }

        return 0;
    }

    /**
     * @param array<string, ManagedAssetSource> $sources
     * @param list<string> $retainedEntries
     * @param list<string> $sourceRoots
     */
    private function syncAgent(string $agent, array $sources, array $retainedEntries, array $sourceRoots, bool $dryRun, bool $force, bool $adoptExisting): int
    {
        if ($sources === []) {
            echo '[WARN] sync skills: no skills found under ' . implode(', ', array_map($this->displayPath(...), $sourceRoots)) . "\n";

            return 0;
        }

        $errors = [];
        foreach ($sources as $directoryName => $source) {
            $skillFile = $source->path . '/SKILL.md';
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

        $targetRoot = (new ManagedAssetTargetCatalog($this->rootPath))->skillsTargetRoot($agent);
        try {
            $manifest = InitSyncManifest::load($targetRoot, 'skills', $agent);
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $desiredEntries = array_keys($sources);
        sort($desiredEntries);
        $retainedEntries = array_values(array_unique(array_merge($retainedEntries, $desiredEntries)));
        sort($retainedEntries);

        $adopted = [];
        foreach ($desiredEntries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if ($this->pathExists($targetPath) && !$manifest->isManaged($entry) && !$force) {
                if ($adoptExisting) {
                    $adopted[$entry] = true;

                    continue;
                }

                echo '[FAIL] sync skills: unmanaged target already exists ' . $targetPath . ' (use --force to overwrite, or --adopt-existing to record it as managed without touching its content)' . "\n";

                return 1;
            }
        }

        foreach ($manifest->staleEntries($retainedEntries) as $staleEntry) {
            $targetPath = $targetRoot . '/' . $staleEntry;
            if ($dryRun) {
                echo '[DRY-RUN] sync skills: remove stale ' . $targetPath . "\n";

                continue;
            }

            $this->removePath($targetPath);
            echo '[OK] sync skills: removed stale ' . $targetPath . "\n";
        }

        foreach ($sources as $entry => $source) {
            $sourceDir = $source->path;
            $targetDir = $targetRoot . '/' . $entry;

            if (isset($adopted[$entry])) {
                echo ($dryRun ? '[DRY-RUN] sync skills: would adopt' : '[OK] sync skills: adopted') . ' existing ' . $targetDir . ' into the manifest (content left untouched)' . "\n";

                continue;
            }

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

            $manifest->writeProjections(
                $sources,
                [HostCapability::SkillProjection],
                array_keys($adopted),
                $retainedEntries,
            );
        }

        echo '[OK] sync skills: synced ' . count($sources) . ' skill file(s) for ' . $agent . ' into ' . $targetRoot . ' from ' . count($sourceRoots) . ' source root(s)' . "\n";
        $reloadHint = $this->reloadHint($agent);
        if ($reloadHint !== null) {
            echo $reloadHint . "\n";
        }

        return 0;
    }

    private function reloadHint(string $agent): ?string
    {
        return match ($agent) {
            'copilot' => "[INFO] Run '/skills reload' in your active Copilot CLI session if needed.",
            'opencode' => '[INFO] Start a fresh OpenCode session if the project skill registry needs to be reloaded.',
            'gemini' => '[INFO] Start a fresh Gemini CLI session if the project skill registry needs to be reloaded.',
            'antigravity' => "[INFO] Run '/skills reload' in your active Antigravity CLI session if needed.",
            'cursor' => '[INFO] Start a fresh Cursor agent session if the project skill registry needs to be reloaded.',
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
        $cliPath = (new RepositoryActivation($this->rootPath))->cliPath();
        $vendorRoot = dirname(dirname($cliPath));

        foreach ($iterator as $item) {
            $relativePath = substr($item->getPathname(), strlen(rtrim($sourceDir, '/')) + 1);
            $destinationPath = $targetDir . '/' . $relativePath;

            if ($item->isDir()) {
                if (!is_dir($destinationPath) && !mkdir($destinationPath, 0o775, true) && !is_dir($destinationPath)) {
                    throw new InvalidArgumentException('Unable to create directory: ' . $destinationPath);
                }

                continue;
            }

            $destinationDir = dirname($destinationPath);
            if (!is_dir($destinationDir) && !mkdir($destinationDir, 0o775, true) && !is_dir($destinationDir)) {
                throw new InvalidArgumentException('Unable to create directory: ' . $destinationDir);
            }

            $content = file_get_contents($item->getPathname());
            if (is_string($content)) {
                $projected = str_replace(
                    [
                        'vendor/bin/agent-loop',
                        'vendor/bin/agent-recall-compiler',
                        'vendor/voku/agent-recall-compiler/',
                    ],
                    [
                        $cliPath,
                        $vendorRoot . '/bin/agent-recall-compiler',
                        $vendorRoot . '/voku/agent-recall-compiler/',
                    ],
                    $content,
                );
                if ($projected !== $content) {
                    if (file_put_contents($destinationPath, $projected) === false) {
                        throw new InvalidArgumentException('Unable to write projected skill file: ' . $item->getPathname());
                    }

                    continue;
                }
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

    private function displayPath(string $path): string
    {
        return PathResolver::relativeTo($this->rootPath, $path);
    }

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['agent', 'config', 'skills-root'];
        $flagOptions = ['dry-run', 'force', 'adopt-existing'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init sync-skills argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!is_string($normalized) || !in_array($normalized, array_merge($valueOptions, $flagOptions), true)) {
                return 'Unknown init sync-skills option: --' . (is_string($normalized) ? $normalized : '');
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
     * @param non-empty-list<string> $skillRoots
     * @return array{files: array<string, string>, errors: list<string>}
     */
    private function collectSkillFiles(array $skillRoots): array
    {
        $files = [];
        $sources = [];
        $errors = [];

        foreach ($skillRoots as $skillsRoot) {
            if (!is_dir($skillsRoot)) {
                continue;
            }

            if (!is_readable($skillsRoot)) {
                $errors[] = '[FAIL] sync skills: unable to read source root: ' . $this->displayPath($skillsRoot);

                continue;
            }

            $entries = scandir($skillsRoot);
            if ($entries === false) {
                $errors[] = '[FAIL] sync skills: unable to read source root: ' . $this->displayPath($skillsRoot);

                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $skillFile = $skillsRoot . '/' . $entry . '/SKILL.md';
                if (!is_file($skillFile)) {
                    continue;
                }

                if (!FirstPartyPackageCatalog::isSkillAllowedForProject($entry, $skillsRoot . '/' . $entry, $this->rootPath)) {
                    continue;
                }

                if (isset($files[$entry])) {
                    $errors[] = '[FAIL] sync skills: duplicate skill id ' . $entry . ' from ' . $sources[$entry] . ' and ' . $this->displayPath($skillFile);
                    continue;
                }

                $files[$entry] = $skillFile;
                $sources[$entry] = $this->displayPath($skillFile);
            }
        }

        ksort($files);

        return ['files' => $files, 'errors' => $errors];
    }
}
