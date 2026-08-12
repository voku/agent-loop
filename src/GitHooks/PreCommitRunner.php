<?php

declare(strict_types=1);

namespace voku\AgentLoop\GitHooks;

/**
 * Runs the configured checks over the files Git actually staged.
 *
 * Every repository re-implements the same five steps: skip merge commits, list the
 * staged files, drop the ones no check should see, batch them so the command line
 * stays sane, and stop at the first failing check. Those steps live here; the check
 * commands themselves come from `.agent-loop/githooks.json`.
 */
final readonly class PreCommitRunner
{
    /** The token a configured command uses for the current batch of staged files. */
    private const string FILES_PLACEHOLDER = '{files}';

    public function __construct(
        private string $rootPath,
        private GitHookConfig $config,
    ) {
    }

    /**
     * @param callable(string): array{output: list<string>, exit: int}|null $commandRunner
     */
    public function run(?callable $commandRunner = null): int
    {
        $runner = $commandRunner ?? $this->defaultRunner();

        if ($this->config->skipMergeCommits && $this->isMergeCommit($runner)) {
            echo "Merge commit detected: skipping pre-commit checks (CI re-runs them).\n";

            return 0;
        }

        if ($this->config->checks === []) {
            echo "No pre-commit checks configured.\n";

            return 0;
        }

        $files = $this->stagedFiles($runner);
        if ($files === []) {
            echo "No affected files found.\n";

            return 0;
        }

        foreach (array_chunk($files, $this->config->batchSize) as $batch) {
            foreach ($this->config->checks as $check) {
                // A per-file check (`php -l`) accepts exactly one path, so it runs once per file
                // instead of once per batch.
                $argumentGroups = $check['per_file']
                    ? array_map(static fn (string $file): array => [$file], $batch)
                    : [$batch];

                foreach ($argumentGroups as $group) {
                    $arguments = implode(' ', array_map(static fn (string $file): string => escapeshellarg($file), $group));
                    $command = str_contains($check['command'], self::FILES_PLACEHOLDER)
                        ? str_replace(self::FILES_PLACEHOLDER, $arguments, $check['command'])
                        : $check['command'] . ' ' . $arguments;

                    $exitCode = $this->passthru($command);
                    if ($exitCode === 0) {
                        continue;
                    }

                    if ($check['optional']) {
                        echo '[WARN] pre-commit: optional check failed: ' . $check['name'] . "\n";

                        continue;
                    }

                    echo '[FAIL] pre-commit: ' . $check['name'] . ' exited with ' . $exitCode . ".\n";

                    return $exitCode;
                }
            }
        }

        return 0;
    }

    /**
     * @param callable(string): array{output: list<string>, exit: int} $runner
     * @return list<string>
     */
    public function stagedFiles(callable $runner): array
    {
        $result = $runner('git -C ' . escapeshellarg($this->rootPath) . ' diff --cached --name-only --diff-filter=ACMRT HEAD');
        if ($result['exit'] !== 0) {
            // A repository without HEAD (the very first commit) has nothing to diff against.
            $result = $runner('git -C ' . escapeshellarg($this->rootPath) . ' diff --cached --name-only --diff-filter=ACMRT');
        }

        $files = [];
        foreach ($result['output'] as $line) {
            $file = trim($line);
            if ($file === '' || !$this->matchesFilePatterns($file) || $this->isExcluded($file)) {
                continue;
            }

            // A staged deletion leaves no file to check.
            if (!is_file(rtrim($this->rootPath, '/') . '/' . $file)) {
                continue;
            }

            $files[$file] = $file;
        }

        return array_values($files);
    }

    private function matchesFilePatterns(string $file): bool
    {
        if ($this->config->filePatterns === []) {
            return true;
        }

        foreach ($this->config->filePatterns as $pattern) {
            if (fnmatch($pattern, $file) || fnmatch($pattern, basename($file))) {
                return true;
            }
        }

        return false;
    }

    private function isExcluded(string $file): bool
    {
        foreach ($this->config->excludePaths as $excluded) {
            if (str_contains('/' . ltrim($file, '/'), $excluded)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param callable(string): array{output: list<string>, exit: int} $runner
     */
    private function isMergeCommit(callable $runner): bool
    {
        $result = $runner('git -C ' . escapeshellarg($this->rootPath) . ' rev-parse -q --verify MERGE_HEAD');

        return $result['exit'] === 0 && $result['output'] !== [];
    }

    /**
     * @return callable(string): array{output: list<string>, exit: int}
     */
    private function defaultRunner(): callable
    {
        return static function (string $command): array {
            $output = [];
            $exitCode = 0;
            exec($command . ' 2>/dev/null', $output, $exitCode);

            return ['output' => $output, 'exit' => $exitCode];
        };
    }

    private function passthru(string $command): int
    {
        $exitCode = 0;
        passthru($command, $exitCode);

        return $exitCode;
    }
}
