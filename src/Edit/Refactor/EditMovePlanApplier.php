<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use Closure;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Edit\EditRunResult;
use voku\AgentMap\Index\AgentMapIndex;

/** Applies already-approved exact edit/move evidence through one transactional host mutation boundary. */
final readonly class EditMovePlanApplier
{
    /** @var (Closure(string, string): bool)|null */
    private ?Closure $renameOperation;

    /** @var (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null */
    private ?Closure $lintOperation;

    /**
     * @param (Closure(string, string): bool)|null $renameOperation
     * @param (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null $lintOperation
     */
    public function __construct(?Closure $renameOperation = null, ?Closure $lintOperation = null)
    {
        $this->renameOperation = $renameOperation;
        $this->lintOperation = $lintOperation;
    }

    public function apply(EditMovePlanEvidence $plan, AgentMapIndex $map, string $root): EditRunResult
    {
        $prepared = $this->preflight($plan, $map, $root);
        $staged = $this->stageAndValidate($prepared['files']);

        try {
            $current = $this->preflight($plan, $map, $root);
            if (
                $current['files'] !== $prepared['files']
                || $current['final_paths'] !== $prepared['final_paths']
                || $current['source_hashes'] !== $prepared['source_hashes']
            ) {
                throw new RuntimeException('Refactor plan source evidence changed while staged; no source was changed.');
            }

            $warnings = $this->publish($staged, $prepared['final_paths'], $prepared['source_hashes'], $root);
        } catch (Throwable $exception) {
            $cleanupFailures = $this->cleanupPaths(array_values($staged));
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Refactor plan failed and staged-file cleanup was incomplete: ' . implode('; ', $cleanupFailures),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        return new EditRunResult(
            status: 'runner_succeeded',
            exitCode: 0,
            stdout: sprintf(
                "%s applied %d edit(s) and %d move(s) across %d source file(s).\n",
                $prepared['plan_type'],
                $prepared['edit_count'],
                $prepared['move_count'],
                count($prepared['files']),
            ),
            stderr: $warnings === [] ? '' : "Refactor plan completed with cleanup warning(s):\n- " . implode("\n- ", $warnings),
        );
    }

    /**
     * @return array{
     *     files: array<string, string>,
     *     final_paths: array<string, string>,
     *     source_hashes: array<string, string>,
     *     plan_type: string,
     *     edit_count: int,
     *     move_count: int
     * }
     */
    public function preflight(EditMovePlanEvidence $plan, AgentMapIndex $map, string $root): array
    {
        $map = $this->withRuntimeRoot($map, $root);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Current agent-map source evidence is stale; rebuild the map before applying the refactor plan.');
        }
        $plan->assertMatches($map);

        /** @var array<string, string> $original */
        $original = [];
        /** @var array<string, list<array{int, int, string}>> $byFile */
        $byFile = [];
        /** @var array<string, string> $sourceHashes */
        $sourceHashes = [];

        foreach ($plan->edits() as $edit) {
            $path = $this->sourcePath($root, $edit->path);
            $content = $original[$path] ??= $this->read($path);
            if (
                !hash_equals($edit->sourceSha256, 'sha256:' . hash('sha256', $content))
                || substr($content, $edit->startFilePos, $edit->endFilePos - $edit->startFilePos + 1) !== $edit->expected
            ) {
                throw new RuntimeException('Refactor edit evidence changed before apply; rebuild and re-plan.');
            }
            $this->rememberSourceHash($sourceHashes, $path, $edit->sourceSha256);
            $byFile[$path][] = [$edit->startFilePos, $edit->endFilePos, $edit->replacement];
        }

        /** @var array<string, string> $files */
        $files = [];
        foreach ($byFile as $path => $fileEdits) {
            usort($fileEdits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
            $content = $original[$path];
            $previousStart = strlen($content);
            foreach ($fileEdits as [$start, $end, $replacement]) {
                if ($end >= $previousStart) {
                    throw new RuntimeException('Refactor plan contains overlapping edit ranges.');
                }
                $content = substr($content, 0, $start) . $replacement . substr($content, $end + 1);
                $previousStart = $start;
            }
            $files[$path] = $content;
        }

        /** @var array<string, string> $finalPaths */
        $finalPaths = [];
        /** @var array<string, true> $moveTargets */
        $moveTargets = [];
        foreach ($plan->moves() as $move) {
            $from = $this->sourcePath($root, $move->fromPath);
            $to = $this->destinationPath($root, $move->toPath);
            $content = $original[$from] ??= $this->read($from);
            if (!hash_equals($move->sourceSha256, 'sha256:' . hash('sha256', $content))) {
                throw new RuntimeException('Refactor move source changed before apply; rebuild and re-plan.');
            }
            $this->rememberSourceHash($sourceHashes, $from, $move->sourceSha256);
            if ($from === $to || isset($finalPaths[$from]) || isset($moveTargets[$to])) {
                throw new RuntimeException('Refactor plan contains duplicate or self-referential file moves.');
            }
            if (file_exists($to) || is_link($to)) {
                throw new RuntimeException('Refactor destination already exists: ' . $this->displayPath($root, $to));
            }

            $finalPaths[$from] = $to;
            $moveTargets[$to] = true;
            if (!isset($files[$from])) {
                $files[$from] = $content;
            }
        }

        foreach ($files as $source => $_content) {
            $finalPaths[$source] ??= $source;
            if (!isset($sourceHashes[$source])) {
                throw new RuntimeException('Refactor plan source is missing hash evidence: ' . $this->displayPath($root, $source));
            }
        }

        $uniqueFinals = array_values($finalPaths);
        if (count($uniqueFinals) !== count(array_unique($uniqueFinals))) {
            throw new RuntimeException('Refactor plan maps multiple sources onto the same final path.');
        }

        ksort($files, SORT_STRING);
        ksort($finalPaths, SORT_STRING);
        ksort($sourceHashes, SORT_STRING);

        return [
            'files' => $files,
            'final_paths' => $finalPaths,
            'source_hashes' => $sourceHashes,
            'plan_type' => $plan->planType(),
            'edit_count' => count($plan->edits()),
            'move_count' => count($plan->moves()),
        ];
    }

    /**
     * @param array<string, string> $files absolute source path => final contents
     * @return array<string, string> absolute source path => staged path
     */
    private function stageAndValidate(array $files): array
    {
        $staged = [];

        try {
            foreach ($files as $path => $content) {
                $temporary = $this->temporaryPath($path, 'stage');
                $staged[$path] = $temporary;
                $written = file_put_contents($temporary, $content, LOCK_EX);
                if (!is_int($written) || $written !== strlen($content)) {
                    throw new RuntimeException('Unable to stage complete refactor source: ' . $path);
                }

                $permissions = fileperms($path);
                if (!is_int($permissions) || !chmod($temporary, $permissions & 0o777)) {
                    throw new RuntimeException('Unable to preserve refactor source permissions: ' . $path);
                }
            }

            foreach ($staged as $path => $temporary) {
                $lint = $this->lint($temporary);
                if ($lint['exit_code'] !== 0) {
                    $detail = trim($lint['stderr'] !== '' ? $lint['stderr'] : $lint['stdout']);
                    throw new RuntimeException(
                        'Rewritten PHP failed syntax validation before publication: '
                        . $path
                        . ($detail === '' ? '' : ' (' . $detail . ')'),
                    );
                }
            }
        } catch (Throwable $exception) {
            $cleanupFailures = $this->cleanupPaths(array_values($staged));
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Refactor plan staging failed and cleanup was incomplete: ' . implode('; ', $cleanupFailures),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        return $staged;
    }

    /**
     * @param array<string, string> $staged absolute source path => staged path
     * @param array<string, string> $finalPaths absolute source path => final path
     * @param array<string, string> $sourceHashes absolute source path => expected sha256
     * @return list<string> non-fatal cleanup warnings after success
     */
    private function publish(array $staged, array $finalPaths, array $sourceHashes, string $root): array
    {
        /** @var array<string, string> $backups */
        $backups = [];
        /** @var array<string, string> $published */
        $published = [];
        /** @var array<string, true> $createdDirectories */
        $createdDirectories = [];

        try {
            foreach ($finalPaths as $source => $final) {
                if ($source !== $final && (file_exists($final) || is_link($final))) {
                    throw new RuntimeException('Refactor destination appeared before publication: ' . $final);
                }
            }

            foreach ($staged as $source => $temporary) {
                $backup = $this->temporaryPath($source, 'backup');
                if (!$this->move($source, $backup)) {
                    throw new RuntimeException('Unable to back up refactor source before publication: ' . $source);
                }
                $backups[$source] = $backup;

                $backupHash = hash_file('sha256', $backup);
                if (!is_string($backupHash) || !hash_equals($sourceHashes[$source], 'sha256:' . $backupHash)) {
                    throw new RuntimeException('Refactor source changed during publication precondition capture: ' . $source);
                }

                $final = $finalPaths[$source];
                if ($source !== $final) {
                    foreach ($this->createDestinationDirectories($root, dirname($final)) as $directory) {
                        $createdDirectories[$directory] = true;
                    }
                    if (file_exists($final) || is_link($final)) {
                        throw new RuntimeException('Refactor destination appeared during publication: ' . $final);
                    }
                }
                if (!$this->move($temporary, $final)) {
                    throw new RuntimeException('Unable to publish staged refactor source: ' . $final);
                }
                $published[$source] = $final;
            }
        } catch (Throwable $exception) {
            $rollbackFailures = $this->rollback($backups, $published);
            $stageCleanupFailures = $this->cleanupPaths(array_values($staged));
            $directoryCleanupFailures = $this->cleanupDirectories(array_keys($createdDirectories));
            $failures = [...$rollbackFailures, ...$stageCleanupFailures, ...$directoryCleanupFailures];
            if ($failures !== []) {
                throw new RuntimeException(
                    'Refactor plan publication failed and rollback was incomplete: ' . implode('; ', $failures),
                    0,
                    $exception,
                );
            }

            throw new RuntimeException('Refactor plan publication failed; every source file was restored.', 0, $exception);
        }

        return $this->cleanupPaths(array_values($backups));
    }

    /**
     * @param array<string, string> $backups absolute original source => backup path
     * @param array<string, string> $published absolute original source => published final path
     * @return list<string> rollback failures
     */
    private function rollback(array $backups, array $published): array
    {
        $failures = [];
        foreach (array_reverse($backups, true) as $source => $backup) {
            $final = $published[$source] ?? null;
            if (is_string($final) && (is_file($final) || is_link($final)) && !unlink($final)) {
                $failures[] = 'unable to remove partially published refactor source ' . $final;
            }
            if (($final === null || $final !== $source) && (is_file($source) || is_link($source)) && !unlink($source)) {
                $failures[] = 'unable to clear original refactor source before rollback ' . $source;
            }
            if ((is_file($backup) || is_link($backup)) && !$this->move($backup, $source)) {
                $failures[] = 'unable to restore refactor source backup ' . $source;
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function cleanupPaths(array $paths): array
    {
        $failures = [];
        foreach ($paths as $path) {
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                $failures[] = 'unable to remove temporary refactor file ' . $path;
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $directories
     * @return list<string>
     */
    private function cleanupDirectories(array $directories): array
    {
        $failures = [];
        foreach (array_reverse($directories) as $directory) {
            if (!is_dir($directory) || is_link($directory)) {
                continue;
            }
            $entries = scandir($directory);
            if (!is_array($entries) || $entries !== ['.', '..']) {
                $failures[] = 'unable to remove created refactor directory because it is not empty: ' . $directory;
                continue;
            }
            if (!rmdir($directory)) {
                $failures[] = 'unable to remove created refactor directory ' . $directory;
            }
        }

        return $failures;
    }

    /** @return list<string> directories created by this call, outermost first */
    private function createDestinationDirectories(string $root, string $directory): array
    {
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Unable to resolve refactor map root while creating destination directories.');
        }
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $directory = str_replace('\\', '/', $directory);

        if (is_dir($directory)) {
            $realDirectory = realpath($directory);
            if (!is_string($realDirectory) || !$this->insideRoot($realRoot, $realDirectory, true)) {
                throw new RuntimeException('Refactor destination directory escapes the map root: ' . $directory);
            }

            return [];
        }
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException('Refactor destination parent is not a directory: ' . $directory);
        }

        $missing = [];
        $probe = $directory;
        while (!is_dir($probe)) {
            if (file_exists($probe) || is_link($probe)) {
                throw new RuntimeException('Refactor destination parent is not a directory: ' . $probe);
            }
            $missing[] = $probe;
            $parent = dirname($probe);
            if ($parent === $probe) {
                throw new RuntimeException('Unable to find an existing refactor destination parent.');
            }
            $probe = $parent;
        }

        $existing = realpath($probe);
        if (!is_string($existing) || !$this->insideRoot($realRoot, $existing, true)) {
            throw new RuntimeException('Refactor destination parent escapes the map root: ' . $directory);
        }

        $created = [];
        try {
            foreach (array_reverse($missing) as $path) {
                if (is_dir($path)) {
                    $realPath = realpath($path);
                    if (!is_string($realPath) || !$this->insideRoot($realRoot, $realPath, true)) {
                        throw new RuntimeException('Refactor destination directory escaped the map root during publication: ' . $path);
                    }
                    continue;
                }
                if (file_exists($path) || is_link($path)) {
                    throw new RuntimeException('Refactor destination parent changed during publication: ' . $path);
                }
                $realParent = realpath(dirname($path));
                if (!is_string($realParent) || !$this->insideRoot($realRoot, $realParent, true)) {
                    throw new RuntimeException('Refactor destination parent escaped the map root during publication: ' . $path);
                }
                if (!mkdir($path, 0o775) && !is_dir($path)) {
                    throw new RuntimeException('Unable to create refactor destination directory: ' . $path);
                }
                $created[] = $path;
            }
        } catch (Throwable $exception) {
            $cleanupFailures = $this->cleanupDirectories($created);
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Refactor destination directory creation failed and cleanup was incomplete: ' . implode('; ', $cleanupFailures),
                    0,
                    $exception,
                );
            }
            throw $exception;
        }

        return $created;
    }

    /** @param array<string, string> $hashes */
    private function rememberSourceHash(array &$hashes, string $path, string $sourceHash): void
    {
        if (isset($hashes[$path]) && !hash_equals($hashes[$path], $sourceHash)) {
            throw new RuntimeException('Refactor plan contains contradictory source hashes for ' . $path . '.');
        }
        $hashes[$path] = $sourceHash;
    }

    private function sourcePath(string $root, string $relative): string
    {
        $relative = $this->relativePath($relative);
        $realRoot = realpath($root);
        $path = is_string($realRoot) ? realpath($realRoot . '/' . $relative) : false;
        if (!is_string($realRoot) || !is_string($path) || !$this->insideRoot($realRoot, $path)) {
            throw new RuntimeException('Refactor source path escapes the map root: ' . $relative);
        }

        return $path;
    }

    private function destinationPath(string $root, string $relative): string
    {
        $relative = $this->relativePath($relative);
        $realRoot = realpath($root);
        if (!is_string($realRoot)) {
            throw new RuntimeException('Unable to resolve refactor map root.');
        }
        $realRoot = rtrim(str_replace('\\', '/', $realRoot), '/');
        $destination = $realRoot . '/' . $relative;
        $parent = dirname($destination);
        $probe = $parent;
        while (!file_exists($probe) && !is_link($probe)) {
            $next = dirname($probe);
            if ($next === $probe) {
                throw new RuntimeException('Unable to find an existing refactor destination parent.');
            }
            $probe = $next;
        }
        $existingParent = realpath($probe);
        if (!is_string($existingParent) || !is_dir($existingParent) || !$this->insideRoot($realRoot, $existingParent, true)) {
            throw new RuntimeException('Refactor destination parent escapes the map root: ' . $relative);
        }

        return $destination;
    }

    private function relativePath(string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('Refactor plan contains an invalid source path.');
        }
        $relative = str_replace('\\', '/', $relative);
        if (str_starts_with($relative, '/') || preg_match('~^[A-Za-z]:/~', $relative) === 1) {
            throw new RuntimeException('Refactor plan path must be relative to the map root: ' . $relative);
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Refactor plan contains a non-canonical relative path: ' . $relative);
            }
        }

        return $relative;
    }

    private function insideRoot(string $root, string $path, bool $allowRoot = false): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return ($allowRoot && $path === $root) || str_starts_with($path, $root . '/');
    }

    private function temporaryPath(string $path, string $purpose): string
    {
        $temporary = $path . '.agent-loop-refactor-plan-' . $purpose . '-' . bin2hex(random_bytes(8));
        if (file_exists($temporary) || is_link($temporary)) {
            throw new RuntimeException('Refactor plan temporary path already exists: ' . $temporary);
        }

        return $temporary;
    }

    private function move(string $from, string $to): bool
    {
        if ($this->renameOperation !== null) {
            return ($this->renameOperation)($from, $to);
        }

        return rename($from, $to);
    }

    /** @return array{exit_code: int, stdout: string, stderr: string} */
    private function lint(string $path): array
    {
        if ($this->lintOperation !== null) {
            return ($this->lintOperation)($path);
        }

        $pipes = [];
        $process = proc_open([PHP_BINARY, '-n', '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start PHP syntax validation for staged refactor source.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (rtrim(str_replace('\\', '/', $map->root), '/') === $root) {
            return $map;
        }

        return new AgentMapIndex(
            $map->schemaVersion,
            $root,
            $map->backend,
            $map->files,
            $map->relations,
            $map->diagnostics,
            $map->fingerprint,
        );
    }

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read refactor source: ' . $path);
        }

        return $content;
    }

    private function displayPath(string $root, string $path): string
    {
        $realRoot = realpath($root);
        $root = is_string($realRoot) ? rtrim(str_replace('\\', '/', $realRoot), '/') : '';
        $path = str_replace('\\', '/', $path);

        return $root !== '' && str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}
