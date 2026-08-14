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
        $result = $runner($this->diffCommand(' HEAD'));
        if ($result['exit'] !== 0) {
            // A repository without HEAD (the very first commit) has nothing to diff against.
            $result = $runner($this->diffCommand(''));
        }

        $files = [];
        foreach ($this->pathRecords($result['output']) as $file) {
            if (!$this->matchesFilePatterns($file) || $this->isExcluded($file)) {
                continue;
            }

            // Nothing to hand a check when the path is gone from the worktree - staged
            // and then deleted, or replaced by a directory. With `-z` this rejects only
            // files that really are absent; it used to absorb every name Git had
            // rewritten on the way out, which is how the filter hid its own blind spot.
            if (!is_file(rtrim($this->rootPath, '/') . '/' . $file)) {
                continue;
            }

            $files[$file] = $file;
        }

        return array_values($files);
    }

    /**
     * `-z` is Git's machine-readable form for a path list: pathnames verbatim, NUL
     * separated.
     *
     * Line-oriented `--name-only` munges names on the way out - `für.php` becomes the
     * literal `"f\303\274r.php"`, and a tab, a quote or a newline in a name is
     * C-quoted no matter what `core.quotePath` says. Such a name matches no `*.php`
     * pattern and names no file on disk, so the file dropped out of the batch, every
     * configured check skipped it, and the hook still exited 0. `core.quotePath=false`
     * covers only the non-ASCII half of that; `-z` is the flag Git provides for the
     * whole of it.
     */
    private function diffCommand(string $revision): string
    {
        return 'git -C ' . escapeshellarg($this->rootPath)
            . ' diff --cached --name-only --diff-filter=ACMRT -z' . $revision;
    }

    /**
     * Splits `-z` output into pathnames.
     *
     * The runner reports stdout as lines, and under `-z` a newline is part of a
     * pathname rather than a separator, so the lines are rejoined before the real
     * separator is applied. Nothing is dropped for failing to look like a path: a
     * record that survives to here is one Git named, and the only later rejections
     * are the configured pattern and exclusion filters.
     *
     * @param list<string> $output
     * @return list<string>
     */
    private function pathRecords(array $output): array
    {
        return array_values(array_filter(
            explode("\0", implode("\n", $output)),
            static fn (string $record): bool => $record !== '',
        ));
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
