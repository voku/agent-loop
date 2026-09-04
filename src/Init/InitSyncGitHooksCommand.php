<?php

declare(strict_types=1);

namespace voku\AgentLoop\Init;

use InvalidArgumentException;
use voku\AgentLoop\Cli\OptionTokens;
use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\PackageResources;

/**
 * Installs the package-owned Git hooks into a host repository and points Git at them.
 *
 * The hooks themselves are generic: they keep the agent-map index in step with the
 * working tree and know how to reach the project container. Everything
 * project-specific - which container, which workdir, which commit template - is
 * configuration, rendered next to the hooks instead of being baked into copies of
 * the same shell script in every repository.
 *
 * `pre-commit` and `commit-msg` are installed too, but they carry no policy either:
 * they call `agent-loop githooks`, which reads the project's own check commands and
 * commit convention from `.agent-loop/githooks.json`.
 *
 * Any other hook in the same directory (`prepare-commit-msg`, `pre-push`, ...) is
 * never touched: only the entries this command installs enter the target manifest,
 * and only those are ever removed again.
 */
final readonly class InitSyncGitHooksCommand
{
    private const string DEFAULT_HOOKS_DIR = '.githooks';

    /** @var list<string> */
    private const array HOOK_ENTRIES = [
        'agent-map-refresh.sh',
        'commit-msg',
        'lib/agent-loop-hooks.sh',
        'post-checkout',
        'post-merge',
        'pre-commit',
    ];

    /** @var list<string> */
    private const array EXECUTABLE_ENTRIES = [
        'agent-map-refresh.sh',
        'commit-msg',
        'post-checkout',
        'post-merge',
        'pre-commit',
    ];

    private const string ENV_ENTRY = 'lib/agent-loop-hooks.env';

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

        $sourceRoot = PackageResources::gitHooksRoot();
        if (!is_dir($sourceRoot)) {
            fwrite(\STDERR, 'Bundled githooks root is missing: ' . $sourceRoot . "\n");

            return 1;
        }

        $hooksDir = trim(OptionTokens::value($tokens, 'hooks-dir') ?? self::DEFAULT_HOOKS_DIR, '/');
        if ($hooksDir === '' || str_starts_with($hooksDir, '/') || str_contains($hooksDir, '..')) {
            fwrite(\STDERR, "The --hooks-dir value must be a relative path inside the repository.\n");

            return 1;
        }

        $targetRoot = $this->rootPath . '/' . $hooksDir;
        $dryRun = OptionTokens::hasFlag($tokens, 'dry-run');
        $force = OptionTokens::hasFlag($tokens, 'force');
        $adoptExisting = OptionTokens::hasFlag($tokens, 'adopt-existing');

        try {
            $manifest = InitSyncManifest::load($targetRoot, 'githooks', 'git');
        } catch (InvalidArgumentException $exception) {
            fwrite(\STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $desiredEntries = array_merge(self::HOOK_ENTRIES, [self::ENV_ENTRY]);
        sort($desiredEntries);

        $adopted = [];
        foreach ($desiredEntries as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if (!is_file($targetPath) || $manifest->isManaged($entry) || $force) {
                continue;
            }

            if ($adoptExisting) {
                $adopted[$entry] = true;

                continue;
            }

            echo '[FAIL] sync githooks: unmanaged target already exists ' . $targetPath . ' (use --force to overwrite, or --adopt-existing to record it as managed without touching its content)' . "\n";

            return 1;
        }

        foreach ($manifest->staleEntries($desiredEntries) as $staleEntry) {
            $targetPath = $targetRoot . '/' . $staleEntry;
            if ($dryRun) {
                echo '[DRY-RUN] sync githooks: remove stale ' . $targetPath . "\n";

                continue;
            }

            if (is_file($targetPath) && !unlink($targetPath)) {
                fwrite(\STDERR, 'Unable to remove stale hook: ' . $targetPath . "\n");

                return 1;
            }

            echo '[OK] sync githooks: removed stale ' . $targetPath . "\n";
        }

        foreach (self::HOOK_ENTRIES as $entry) {
            $targetPath = $targetRoot . '/' . $entry;
            if (isset($adopted[$entry])) {
                // Adoption keeps whatever the repository already wrote, but a hook
                // Git cannot execute is not an installed hook. The execute bit is
                // the one property that decides whether the file runs at all, so it
                // is enforced even here; the content stays untouched.
                $repaired = !$dryRun
                    && in_array($entry, self::EXECUTABLE_ENTRIES, true)
                    && !is_executable($targetPath)
                    && chmod($targetPath, 0o755);

                echo '[OK] sync githooks: adopted existing ' . $targetPath . ' into the manifest (content left untouched'
                    . ($repaired ? '; execute bit added so Git can run it' : '') . ')' . "\n";

                continue;
            }

            if ($dryRun) {
                echo '[DRY-RUN] sync githooks: install ' . $entry . ' -> ' . $targetPath . "\n";

                continue;
            }

            $content = file_get_contents($sourceRoot . '/' . $entry);
            if (!is_string($content)) {
                fwrite(\STDERR, 'Unable to read bundled hook: ' . $sourceRoot . '/' . $entry . "\n");

                return 1;
            }

            $this->writeFile($targetPath, $content);
            if (in_array($entry, self::EXECUTABLE_ENTRIES, true)) {
                chmod($targetPath, 0o755);
            }

            echo '[OK] sync githooks: installed ' . $entry . ' -> ' . $targetPath . "\n";
        }

        $environment = $this->renderEnvironment($tokens);
        if (isset($adopted[self::ENV_ENTRY])) {
            echo '[OK] sync githooks: adopted existing ' . $targetRoot . '/' . self::ENV_ENTRY . ' into the manifest (content left untouched)' . "\n";
        } elseif ($dryRun) {
            echo '[DRY-RUN] sync githooks: write ' . self::ENV_ENTRY . ' -> ' . $targetRoot . '/' . self::ENV_ENTRY . "\n";
        } else {
            $this->writeFile($targetRoot . '/' . self::ENV_ENTRY, $environment);
            echo '[OK] sync githooks: wrote ' . self::ENV_ENTRY . ' -> ' . $targetRoot . '/' . self::ENV_ENTRY . "\n";
        }

        if (!$dryRun) {
            $manifest->write($desiredEntries);
        }

        $gitExit = $this->applyGitConfiguration($tokens, $hooksDir, $dryRun);
        if ($gitExit !== 0) {
            return $gitExit;
        }

        echo '[OK] sync githooks: synced ' . count(self::HOOK_ENTRIES) . ' hook file(s) into ' . $targetRoot . "\n";

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function applyGitConfiguration(array $tokens, string $hooksDir, bool $dryRun): int
    {
        if (OptionTokens::hasFlag($tokens, 'skip-git-config')) {
            echo '[INFO] sync githooks: left core.hooksPath and commit.template untouched (--skip-git-config).' . "\n";

            return 0;
        }

        if (!GitWorkTree::detected($this->rootPath)) {
            echo '[WARN] sync githooks: no Git working tree detected; skipped core.hooksPath and commit.template.' . "\n";

            return 0;
        }

        $settings = ['core.hooksPath' => $hooksDir];

        $commitTemplate = OptionTokens::value($tokens, 'commit-template');
        if ($commitTemplate !== null) {
            if (!is_file($this->rootPath . '/' . ltrim($commitTemplate, '/'))) {
                echo '[FAIL] sync githooks: commit template not found: ' . $commitTemplate . "\n";

                return 1;
            }

            $settings['commit.template'] = $commitTemplate;
        }

        foreach ($settings as $key => $value) {
            if ($dryRun) {
                echo '[DRY-RUN] sync githooks: git config ' . $key . ' ' . $value . "\n";

                continue;
            }

            $command = 'git -C ' . escapeshellarg($this->rootPath) . ' config ' . escapeshellarg($key) . ' ' . escapeshellarg($value);
            exec($command, $output, $exitCode);
            if ($exitCode !== 0) {
                fwrite(\STDERR, 'Unable to set ' . $key . ' in the repository-local Git configuration.' . "\n");

                return 1;
            }

            echo '[OK] sync githooks: ' . $key . '=' . $value . "\n";
        }

        return 0;
    }

    /**
     * @param list<string> $tokens
     */
    private function renderEnvironment(array $tokens): string
    {
        $values = [
            // The hooks fall back to `vendor/bin/agent-loop`, which does not exist
            // in a repository whose own root package is agent-loop: every commit
            // there died with "vendor/bin/agent-loop: No such file or directory".
            // The resolved path is written out so the hooks never depend on that
            // guess.
            'AGENT_LOOP_BIN' => (new RepositoryActivation($this->rootPath))->cliPath(),
            'AGENT_LOOP_CONTAINER_SERVICE' => OptionTokens::value($tokens, 'container-service') ?? '',
            'AGENT_LOOP_CONTAINER_IMAGE' => OptionTokens::value($tokens, 'container-image') ?? '',
            'AGENT_LOOP_CONTAINER_WORKDIR' => OptionTokens::value($tokens, 'container-workdir') ?? '',
            'AGENT_LOOP_CONTAINER_USER' => OptionTokens::value($tokens, 'container-user') ?? '',
            'AGENT_LOOP_MAP_INDEX' => OptionTokens::value($tokens, 'map-index') ?? '',
            'AGENT_LOOP_MAP_SEARCH_DATABASE' => OptionTokens::value($tokens, 'map-search-database') ?? '',
        ];

        $lines = [
            '# Generated by `agent-loop init sync-githooks`. Edit the command, not this file.',
            '# Empty values fall back to plain host execution or the hook defaults.',
        ];
        foreach ($values as $name => $value) {
            if ($value === '') {
                continue;
            }

            $lines[] = $name . '=' . escapeshellarg($value);
        }

        return implode("\n", $lines) . "\n";
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

    /**
     * @param list<string> $tokens
     */
    private function validateTokens(array $tokens): ?string
    {
        $valueOptions = [
            'hooks-dir',
            'commit-template',
            'container-service',
            'container-image',
            'container-workdir',
            'container-user',
            'map-index',
            'map-search-database',
        ];
        $flagOptions = ['dry-run', 'force', 'adopt-existing', 'skip-git-config'];

        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if (!str_starts_with($token, '--')) {
                return 'Unknown init sync-githooks argument: ' . $token;
            }

            $normalized = strtok(substr($token, 2), '=');
            if (!in_array($normalized, array_merge($valueOptions, $flagOptions), true)) {
                return 'Unknown init sync-githooks option: --' . $normalized;
            }

            if (in_array($normalized, $valueOptions, true) && !str_contains($token, '=')) {
                $candidate = $tokens[$i + 1] ?? null;
                if (!is_string($candidate) || str_starts_with($candidate, '--')) {
                    return 'Missing value for init sync-githooks option: --' . $normalized;
                }

                ++$i;
            }
        }

        return null;
    }
}
