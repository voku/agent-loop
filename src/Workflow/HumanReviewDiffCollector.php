<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\ProjectLayout;

final readonly class HumanReviewDiffCollector
{
    private const int COMMAND_TIMEOUT_SECONDS = 30;

    public function __construct(private string $rootPath)
    {
    }

    public function collect(TaskContract $contract): HumanReviewDiff
    {
        if ($contract->baseCommit === null) {
            return HumanReviewDiff::unavailable(
                null,
                'Contract has no base commit; Git change orientation cannot be derived without guessing a baseline.',
            );
        }

        $root = realpath($this->rootPath);
        if ($root === false || !is_dir($root)) {
            return HumanReviewDiff::unavailable($contract->baseCommit, 'Project root is unavailable.');
        }

        $base = $contract->baseCommit;
        $verified = $this->run($root, ['git', 'rev-parse', '--verify', $base . '^{commit}']);
        if ($verified['exit'] !== 0) {
            return HumanReviewDiff::unavailable($base, 'Contract base commit is not available in this Git checkout.');
        }

        $scope = $contract->scope;
        $layout = new ProjectLayout($root);
        $stateRoot = $layout->display($layout->stateRoot());
        $allTrackedNames = $this->run($root, [
            'git', 'diff', '--name-only', '-z', '--no-ext-diff', '--find-renames', $base, '--',
        ]);
        $allUntrackedNames = $this->run($root, [
            'git', 'ls-files', '--others', '--exclude-standard', '-z', '--',
        ]);
        $trackedPatch = $this->run($root, [
            'git', 'diff', '--no-ext-diff', '--no-color', '--find-renames', $base, '--', ...$scope,
        ]);
        $scopedUntrackedNames = $this->run($root, [
            'git', 'ls-files', '--others', '--exclude-standard', '-z', '--', ...$scope,
        ]);

        if (
            $allTrackedNames['exit'] !== 0
            || $allUntrackedNames['exit'] !== 0
            || $trackedPatch['exit'] !== 0
            || $scopedUntrackedNames['exit'] !== 0
        ) {
            return HumanReviewDiff::unavailable($base, 'Git could not derive the review change orientation.');
        }

        $tracked = self::visibleChangedPaths(self::nulList($allTrackedNames['stdout']), $scope, $stateRoot);
        $untracked = self::visibleChangedPaths(self::nulList($allUntrackedNames['stdout']), $scope, $stateRoot);
        $scopedUntracked = self::nulList($scopedUntrackedNames['stdout']);
        $changed = array_values(array_unique([...$tracked, ...$untracked]));
        sort($changed, SORT_STRING);
        sort($untracked, SORT_STRING);

        $patch = rtrim($trackedPatch['stdout'], "\n");
        foreach ($scopedUntracked as $path) {
            $untrackedPatch = $this->untrackedPatch($root, $path);
            if ($untrackedPatch === '') {
                continue;
            }
            $patch .= ($patch === '' ? '' : "\n\n") . $untrackedPatch;
        }

        return new HumanReviewDiff(
            available: true,
            baseCommit: $base,
            changedFiles: $changed,
            untrackedFiles: $untracked,
            patch: $patch,
        );
    }

    /** @return list<string> */
    private static function nulList(string $value): array
    {
        $items = array_values(array_filter(
            explode("\0", $value),
            static fn (string $item): bool => $item !== '',
        ));
        sort($items, SORT_STRING);

        return $items;
    }

    /**
     * Workflow-generated state is not an application change and must not make
     * the review projection observe itself on a second render. A Contract can
     * still explicitly review a state-root path by naming that path directly;
     * broad scope such as `.` does not promote generated workflow state into
     * implementation content.
     *
     * @param list<string> $paths
     * @param list<string> $scope
     * @return list<string>
     */
    private static function visibleChangedPaths(array $paths, array $scope, string $stateRoot): array
    {
        return array_values(array_filter(
            $paths,
            static fn (string $path): bool => !self::inside($path, $stateRoot)
                || self::explicitStateScopeContains($path, $scope, $stateRoot),
        ));
    }

    /** @param list<string> $scope */
    private static function explicitStateScopeContains(string $path, array $scope, string $stateRoot): bool
    {
        foreach ($scope as $entry) {
            $entry = trim(str_replace('\\', '/', $entry), '/');
            if ($entry === '' || $entry === '.' || !self::inside($entry, $stateRoot)) {
                continue;
            }
            if ($path === $entry || str_starts_with($path, $entry . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function inside(string $path, string $root): bool
    {
        $normalizedRoot = str_replace('\\', '/', $root);
        if (
            $normalizedRoot === ''
            || str_starts_with($normalizedRoot, '/')
            || preg_match('/^[A-Za-z]:\//', $normalizedRoot) === 1
        ) {
            return false;
        }

        $path = trim(str_replace('\\', '/', $path), '/');
        $root = trim($normalizedRoot, '/');

        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function untrackedPatch(string $root, string $path): string
    {
        $absolute = $root . '/' . $path;
        if (!is_file($absolute) || is_link($absolute)) {
            return '';
        }

        $content = file_get_contents($absolute);
        if ($content === false) {
            return '';
        }

        $displayPath = self::patchPath($path);
        $header = "diff --git a/{$displayPath} b/{$displayPath}\n"
            . "new file mode untracked\n"
            . "--- /dev/null\n"
            . "+++ b/{$displayPath}\n";

        if (str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            return $header . "Binary files /dev/null and b/{$displayPath} differ";
        }

        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = $normalized === '' ? [] : explode("\n", $normalized);
        $endsWithNewline = $normalized !== '' && str_ends_with($normalized, "\n");
        if ($endsWithNewline) {
            array_pop($lines);
        }

        $body = $header . sprintf("@@ -0,0 +1,%d @@\n", count($lines));
        foreach ($lines as $line) {
            $body .= '+' . $line . "\n";
        }
        if (!$endsWithNewline && $normalized !== '') {
            $body .= "\\ No newline at end of file\n";
        }

        return rtrim($body, "\n");
    }

    private static function patchPath(string $path): string
    {
        if (preg_match('/^[A-Za-z0-9._\/-]+$/', $path) === 1) {
            return $path;
        }

        $encoded = json_encode($path, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '[unprintable-path]';
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function run(string $workingDirectory, array $command): array
    {
        /** @var array<int, resource> $pipes */
        $pipes = [];
        $process = proc_open(
            $command,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }

        /** @var array<int, resource> $openPipes */
        $openPipes = [];
        foreach ([1, 2] as $index) {
            $pipe = $pipes[$index] ?? null;
            if (!is_resource($pipe)) {
                $this->closePipes($openPipes);
                proc_terminate($process);
                proc_close($process);

                return ['exit' => 127, 'stdout' => '', 'stderr' => 'Git process did not expose output pipes.'];
            }
            stream_set_blocking($pipe, false);
            $openPipes[$index] = $pipe;
        }

        $buffers = [1 => '', 2 => ''];
        $deadline = microtime(true) + self::COMMAND_TIMEOUT_SECONDS;
        $timedOut = false;
        $terminationStartedAt = null;
        $observedExitCode = null;

        while (true) {
            $status = proc_get_status($process);
            $running = $status['running'];
            if (!$running && $observedExitCode === null && $status['exitcode'] >= 0) {
                $observedExitCode = $status['exitcode'];
            }

            $now = microtime(true);
            if (!$timedOut && $running && $now >= $deadline) {
                $timedOut = true;
                $terminationStartedAt = $now;
                proc_terminate($process);
            } elseif (
                $timedOut
                && $running
                && $terminationStartedAt !== null
                && $now >= $terminationStartedAt + 2.0
            ) {
                proc_terminate($process, 9);
                $this->closePipes($openPipes);
            }

            if ($openPipes !== []) {
                $read = array_values($openPipes);
                $write = null;
                $except = null;
                $selected = stream_select($read, $write, $except, 0, 200000);
                if ($selected === false) {
                    $this->closePipes($openPipes);
                    if ($running) {
                        proc_terminate($process);
                    }
                    proc_close($process);

                    return ['exit' => 127, 'stdout' => $buffers[1], 'stderr' => 'Unable to read Git process output.'];
                }

                foreach ($read as $stream) {
                    $index = array_search($stream, $openPipes, true);
                    if (!is_int($index)) {
                        continue;
                    }
                    $chunk = fread($stream, 8192);
                    if (is_string($chunk) && $chunk !== '') {
                        $buffers[$index] .= $chunk;
                    }
                    if (($chunk === false || $chunk === '') && feof($stream)) {
                        fclose($stream);
                        unset($openPipes[$index]);
                    }
                }
            } elseif ($running) {
                usleep(200000);
            }

            if (!$running && $openPipes === []) {
                break;
            }
        }

        $closeExitCode = proc_close($process);
        $exitCode = $closeExitCode >= 0 ? $closeExitCode : ($observedExitCode ?? $closeExitCode);
        if ($timedOut) {
            $exitCode = 124;
            $buffers[2] .= ($buffers[2] === '' ? '' : "\n")
                . 'Git command timed out after ' . self::COMMAND_TIMEOUT_SECONDS . ' seconds.';
        }

        return [
            'exit' => $exitCode,
            'stdout' => $buffers[1],
            'stderr' => $buffers[2],
        ];
    }

    /** @param array<int, resource> $pipes */
    private function closePipes(array &$pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $pipes = [];
    }
}
