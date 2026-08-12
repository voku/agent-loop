<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use voku\AgentLoop\Cli\OptionTokens;

/**
 * Installs the package-owned isolated tool projects into a host repository.
 *
 * The external evidence tools are not dependencies of `agent-loop` and must not
 * become dependencies of the package under test either: they require PHP 8.3+,
 * and agent tooling has no business in the dependency tree of a library's
 * consumers. Each therefore gets its own small Composer project under `tools/`,
 * which this command writes from package-owned templates.
 *
 * It writes project files and nothing else. Composer is never executed here:
 * resolving dependencies reaches the network, picks versions, and belongs to
 * the operator who can see the result. The command prints the exact command to
 * run next instead of guessing on their behalf.
 */
final readonly class InitSyncToolsCommand
{
    private const string DEFAULT_TOOLS_DIR = 'tools';

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

        $paths = AgentAssetSourcePaths::fromSources($this->rootPath, $config['paths'], $this->readPathOverrides($tokens));
        $sourceRoot = $paths->absoluteToolsRoot();
        if (!is_dir($sourceRoot)) {
            fwrite(\STDERR, 'Tool project root is missing: ' . $paths->toolsRoot() . "\n");

            return 1;
        }

        $entries = $this->collectEntries($sourceRoot);
        if ($entries === []) {
            echo '[WARN] sync tools: no tool projects found under ' . $paths->toolsRoot() . "\n";

            return 0;
        }

        $toolsDir = trim(OptionTokens::value($tokens, 'tools-dir') ?? self::DEFAULT_TOOLS_DIR, '/');
        if ($toolsDir === '' || str_contains($toolsDir, '..')) {
            fwrite(\STDERR, "The --tools-dir value must be a relative path inside the repository.\n");

            return 1;
        }

        $targetRoot = rtrim($this->rootPath, '/') . '/' . $toolsDir;
        $dryRun = OptionTokens::hasFlag($tokens, 'dry-run');
        $force = OptionTokens::hasFlag($tokens, 'force');
        $adoptExisting = OptionTokens::hasFlag($tokens, 'adopt-existing');

        try {
            $manifest = InitSyncManifest::load($targetRoot, 'tools', 'evidence');
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $adopted = [];
        foreach ($entries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if (is_file($targetPath) && !$manifest->isManaged($entry) && !$force) {
                if ($adoptExisting) {
                    $adopted[$entry] = true;

                    continue;
                }

                echo '[FAIL] sync tools: unmanaged target already exists ' . $targetPath
                    . ' (use --force to overwrite, or --adopt-existing to record it as managed without touching its content)' . "\n";

                return 1;
            }
        }

        foreach ($entries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if (isset($adopted[$entry])) {
                echo '[OK] sync tools: adopted ' . $toolsDir . '/' . $entry . "\n";

                continue;
            }

            if ($dryRun) {
                echo '[OK] sync tools: would write ' . $toolsDir . '/' . $entry . "\n";

                continue;
            }

            if (!$this->writeEntry($sourceRoot . '/' . $entry, $targetPath)) {
                fwrite(\STDERR, 'Unable to write ' . $targetPath . "\n");

                return 1;
            }

            echo '[OK] sync tools: wrote ' . $toolsDir . '/' . $entry . "\n";
        }

        foreach ($manifest->staleEntries($entries) as $stale) {
            $stalePath = $targetRoot . '/' . $stale;
            if (!is_file($stalePath)) {
                continue;
            }

            if ($dryRun) {
                echo '[OK] sync tools: would remove ' . $toolsDir . '/' . $stale . "\n";

                continue;
            }

            unlink($stalePath);
            echo '[OK] sync tools: removed ' . $toolsDir . '/' . $stale . "\n";
        }

        if (!$dryRun) {
            $manifest->write($entries);
        }

        $this->reportNextSteps($toolsDir, $entries);

        return 0;
    }

    /**
     * A tool project is not usable until its dependencies exist, and only the
     * operator can decide to reach the network. Name the commands; run none.
     *
     * @param list<string> $entries
     */
    private function reportNextSteps(string $toolsDir, array $entries): void
    {
        $projects = [];
        foreach ($entries as $entry) {
            if (str_ends_with($entry, '/composer.json')) {
                $projects[] = dirname($entry);
            }
        }

        if ($projects === []) {
            return;
        }

        echo "\nInstall each tool project, then let the inventory find them:\n";
        foreach ($projects as $project) {
            echo '  composer install --working-dir=' . $toolsDir . '/' . $project . "\n";
        }

        echo '  agent-loop init tools --refresh' . "\n";
        echo "\nKeep the generated lock files, ignore the vendor directories:\n";
        echo '  /' . $toolsDir . '/*/vendor/' . "\n";
    }

    private function writeEntry(string $sourcePath, string $targetPath): bool
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            return false;
        }

        if (!copy($sourcePath, $targetPath)) {
            return false;
        }

        // A runner that is not executable is a runner an agent cannot invoke.
        if (is_executable($sourcePath)) {
            chmod($targetPath, 0o755);
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private function collectEntries(string $sourceRoot): array
    {
        $entries = [];
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($sourceRoot) + 1);
            // Only files inside a project directory are projected: a stray file
            // at the root has no tool project to belong to.
            if (!str_contains($relative, '/') || str_starts_with(basename($relative), '.')) {
                continue;
            }

            $entries[] = $relative;
        }

        sort($entries);

        return $entries;
    }

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = ['config', 'tools-root', 'tools-dir'];
        $flagOptions = ['dry-run', 'force', 'adopt-existing'];
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!str_starts_with($token, '--')) {
                return 'Unknown init sync-tools argument: ' . $token;
            }

            $normalized = (string) strtok(substr($token, 2), '=');

            if (in_array($normalized, $flagOptions, true)) {
                continue;
            }

            if (!in_array($normalized, $valueOptions, true)) {
                return 'Unknown init sync-tools option: --' . $normalized;
            }

            if (!str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init sync-tools option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }

    /**
     * @param list<string> $tokens
     *
     * @return array<string, string>
     */
    private function readPathOverrides(array $tokens): array
    {
        $overrides = [];
        $value = OptionTokens::value($tokens, 'tools-root');
        if ($value !== null) {
            $overrides['tools-root'] = $value;
        }

        return $overrides;
    }
}
