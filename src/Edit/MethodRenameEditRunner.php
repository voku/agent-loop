<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use Closure;
use RuntimeException;
use Throwable;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** Applies only a complete, current, safe agent-map method rename plan. */
final readonly class MethodRenameEditRunner implements EditRunner
{
    /** @var (Closure(string, string): bool)|null */
    private ?Closure $renameOperation;

    /** @var (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null */
    private ?Closure $lintOperation;

    /**
     * @param (Closure(string, string): bool)|null $renameOperation
     * @param (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null $lintOperation
     */
    public function __construct(
        private MethodRenamePlanner $planner = new MethodRenamePlanner(),
        private IndexReader $reader = new IndexReader(),
        ?Closure $renameOperation = null,
        ?Closure $lintOperation = null,
    ) {
        $this->renameOperation = $renameOperation;
        $this->lintOperation = $lintOperation;
    }

    /** Applies one freshly planned rename only after complete validation and staging. */
    public function run(EditExecution $execution): EditRunResult
    {
        $replacement = $execution->request->replacementMethod;
        if ($replacement === null) {
            throw new RuntimeException('Method rename runner has no replacement method.');
        }

        // Re-read at the mutation boundary instead of trusting the map prepared earlier.
        $map = $this->reader->read($execution->request->mapIndex);
        $map = $this->withRuntimeRoot($map, $execution->request->mapRoot);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Method rename evidence is stale; rebuild the map and re-plan before applying.');
        }

        $plan = $this->planner->plan($map, $execution->request->target, $replacement)->toArray();
        $files = $this->preflight($plan, $map, $execution->request->mapRoot);
        $staged = $this->stageAndValidate($files);

        try {
            // Close the staging-time TOCTOU window before the first source file is moved.
            if ($this->preflight($plan, $map, $execution->request->mapRoot) !== $files) {
                throw new RuntimeException('Method rename source evidence changed while staged; no source was changed.');
            }
            $warnings = $this->publish($staged);
        } catch (Throwable $exception) {
            $cleanupFailures = $this->cleanupPaths(array_values($staged));
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Method rename failed and staged-file cleanup was incomplete: ' . implode('; ', $cleanupFailures),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        return new EditRunResult(
            status: 'runner_succeeded',
            exitCode: 0,
            stdout: sprintf("Method rename plan applied %d edit(s) across %d file(s).\n", count($plan['edits']), count($files)),
            stderr: $warnings === [] ? '' : "Method rename completed with cleanup warning(s):\n- " . implode("\n- ", $warnings),
        );
    }

    /**
     * Validates the public plan contract and computes the complete in-memory result without mutation.
     *
     * @param array<string, mixed> $plan
     * @return array<string, string> absolute path => updated contents
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        if (($plan['type'] ?? null) !== 'method_rename_plan' || ($plan['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map method rename plan contract version.');
        }
        if (($plan['stale_evidence'] ?? null) !== []) {
            throw new RuntimeException('Method rename evidence is stale; rebuild the map and re-plan before applying.');
        }
        if (($plan['blockers'] ?? null) !== []) {
            throw new RuntimeException('Method rename plan has semantic blockers; no source was changed.');
        }
        if (($plan['status'] ?? null) === 'review_required') {
            throw new RuntimeException('Method rename plan requires explicit review; no source was changed.');
        }
        if (($plan['status'] ?? null) !== 'safe') {
            throw new RuntimeException('Method rename plan is not safe; no source was changed.');
        }

        $provenance = $plan['provenance'] ?? null;
        if (!is_array($provenance)
            || ($provenance['map_digest'] ?? null) !== $map->mapDigest()
            || ($provenance['backend'] ?? null) !== $map->backend
            || ($provenance['analysis_fingerprint'] ?? null) !== $map->fingerprint?->toArray()
        ) {
            throw new RuntimeException('Method rename plan does not match the current map identity; rebuild and re-plan.');
        }

        $edits = $plan['edits'] ?? null;
        if (!is_array($edits) || $edits === []) {
            throw new RuntimeException('Safe method rename plan contains no edits.');
        }

        $original = [];
        $byFile = [];
        foreach ($edits as $edit) {
            if (!is_array($edit)) {
                throw new RuntimeException('Method rename plan contains an invalid edit.');
            }
            $path = $this->sourcePath($root, $edit['path'] ?? null);
            $content = $original[$path] ??= $this->read($path);
            $start = $edit['start_file_pos'] ?? null;
            $end = $edit['end_file_pos'] ?? null;
            $expected = $edit['expected'] ?? null;
            $replacement = $edit['replacement'] ?? null;
            $sourceHash = $edit['source_sha256'] ?? null;
            if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start
                || !is_string($expected) || !is_string($replacement) || !is_string($sourceHash)
                || !hash_equals($sourceHash, 'sha256:' . hash('sha256', $content))
                || substr($content, $start, $end - $start + 1) !== $expected
            ) {
                throw new RuntimeException('Method rename edit evidence changed before apply; rebuild and re-plan.');
            }
            $byFile[$path][] = [$start, $end, $replacement];
        }

        $updated = [];
        foreach ($byFile as $path => $fileEdits) {
            usort($fileEdits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
            $content = $original[$path];
            $previousStart = strlen($content);
            foreach ($fileEdits as [$start, $end, $replacement]) {
                if ($end >= $previousStart) {
                    throw new RuntimeException('Method rename plan contains overlapping edit ranges.');
                }
                $content = substr($content, 0, $start) . $replacement . substr($content, $end + 1);
                $previousStart = $start;
            }
            $updated[$path] = $content;
        }
        ksort($updated, SORT_STRING);

        return $updated;
    }

    /**
     * Writes every candidate to an adjacent stage file, preserves its mode, then syntax-checks all candidates.
     *
     * @param array<string, string> $files absolute path => updated contents
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
                    throw new RuntimeException('Unable to stage complete method rename source: ' . $path);
                }

                $permissions = fileperms($path);
                if (!is_int($permissions) || !chmod($temporary, $permissions & 0o777)) {
                    throw new RuntimeException('Unable to preserve method rename source permissions: ' . $path);
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
                    'Method rename staging failed and cleanup was incomplete: ' . implode('; ', $cleanupFailures),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        return $staged;
    }

    /**
     * Publishes staged files with per-source backups and restores every published source on failure.
     *
     * @param array<string, string> $staged absolute source path => staged path
     * @return list<string> non-fatal cleanup warnings after a successful commit
     */
    private function publish(array $staged): array
    {
        /** @var array<string, string> $backups */
        $backups = [];

        try {
            foreach ($staged as $path => $temporary) {
                $backup = $this->temporaryPath($path, 'backup');
                if (!$this->move($path, $backup)) {
                    throw new RuntimeException('Unable to back up method rename source before publication: ' . $path);
                }
                $backups[$path] = $backup;

                if (!$this->move($temporary, $path)) {
                    throw new RuntimeException('Unable to publish staged method rename source: ' . $path);
                }
            }
        } catch (Throwable $exception) {
            $rollbackFailures = $this->rollback($backups);
            $stageCleanupFailures = $this->cleanupPaths(array_values($staged));
            $failures = [...$rollbackFailures, ...$stageCleanupFailures];
            if ($failures !== []) {
                throw new RuntimeException(
                    'Method rename publication failed and rollback was incomplete: ' . implode('; ', $failures),
                    0,
                    $exception,
                );
            }

            throw new RuntimeException(
                'Method rename publication failed; every source file was restored.',
                0,
                $exception,
            );
        }

        return $this->cleanupPaths(array_values($backups));
    }

    /**
     * Restores original source files from backups in reverse publication order.
     *
     * @param array<string, string> $backups absolute source path => backup path
     * @return list<string> rollback failures
     */
    private function rollback(array $backups): array
    {
        $failures = [];
        foreach (array_reverse($backups, true) as $path => $backup) {
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                $failures[] = 'unable to remove partially published source ' . $path;
            }
            if ((is_file($backup) || is_link($backup)) && !$this->move($backup, $path)) {
                $failures[] = 'unable to restore source backup ' . $path;
            }
        }

        return $failures;
    }

    /**
     * @param list<string> $paths
     * @return list<string> cleanup failures
     */
    private function cleanupPaths(array $paths): array
    {
        $failures = [];
        foreach ($paths as $path) {
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                $failures[] = 'unable to remove temporary file ' . $path;
            }
        }

        return $failures;
    }

    /** Creates a collision-resistant adjacent path so rename publication stays on one filesystem. */
    private function temporaryPath(string $path, string $purpose): string
    {
        $temporary = $path . '.agent-loop-method-rename-' . $purpose . '-' . bin2hex(random_bytes(8));
        if (file_exists($temporary) || is_link($temporary)) {
            throw new RuntimeException('Method rename temporary path already exists: ' . $temporary);
        }

        return $temporary;
    }

    /** Moves one filesystem path using the production operation or the injected failure-test seam. */
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
            throw new RuntimeException('Unable to start PHP syntax validation for staged method rename source.');
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

    /** Rebinds a persisted map to the runtime project root without changing map identity. */
    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        if (rtrim(str_replace('\\', '/', $map->root), '/') === rtrim(str_replace('\\', '/', $root), '/')) {
            return $map;
        }

        return new AgentMapIndex($map->schemaVersion, rtrim(str_replace('\\', '/', $root), '/'), $map->backend, $map->files, $map->relations, $map->diagnostics, $map->fingerprint);
    }

    /** Resolves one plan-relative source path and rejects root escapes. */
    private function sourcePath(string $root, mixed $relative): string
    {
        if (!is_string($relative) || $relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('Method rename plan contains an invalid source path.');
        }
        $root = realpath($root);
        $path = realpath(($root ?: '') . '/' . $relative);
        if (!is_string($root) || !is_string($path) || !str_starts_with(str_replace('\\', '/', $path), rtrim(str_replace('\\', '/', $root), '/') . '/')) {
            throw new RuntimeException('Method rename edit escapes the map root: ' . $relative);
        }

        return $path;
    }

    /** Reads one source file or fails with its concrete path. */
    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read method rename source: ' . $path);
        }

        return $content;
    }
}
