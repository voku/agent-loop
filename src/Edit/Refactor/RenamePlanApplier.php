<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use Closure;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Edit\EditRunResult;
use voku\AgentMap\Index\AgentMapIndex;

/**
 * Applies only the four first-party rename plan contracts owned by agent-map.
 *
 * This is deliberately not a generic edit-plan engine. Method, function, class and property rename
 * are the complete allowlist; any other plan type fails closed before source inspection or writes.
 */
final readonly class RenamePlanApplier
{
    /** @var list<string> */
    private const PLAN_TYPES = [
        'method_rename_plan',
        'function_rename_plan',
        'class_rename_plan',
        'property_rename_plan',
    ];

    /** @var array<string, string> */
    private const TARGET_PREFIX = [
        'method_rename_plan' => 'method:',
        'function_rename_plan' => 'function:',
        'class_rename_plan' => 'class:',
        'property_rename_plan' => 'property:',
    ];

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

    /**
     * Validates, stages, syntax-checks and transactionally publishes one safe rename plan.
     *
     * @param array<string, mixed> $plan
     */
    public function apply(array $plan, AgentMapIndex $map, string $root): EditRunResult
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
                throw new RuntimeException('Rename plan source evidence changed while staged; no source was changed.');
            }

            $warnings = $this->publish(
                $staged,
                $prepared['final_paths'],
                $prepared['source_hashes'],
            );
        } catch (Throwable $exception) {
            $cleanupFailures = $this->cleanupPaths(array_values($staged));
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Rename plan failed and staged-file cleanup was incomplete: ' . implode('; ', $cleanupFailures),
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
            stderr: $warnings === [] ? '' : "Rename plan completed with cleanup warning(s):\n- " . implode("\n- ", $warnings),
        );
    }

    /**
     * Validates the public rename contract and computes every final file without mutation.
     *
     * @param array<string, mixed> $plan
     * @return array{
     *     files: array<string, string>,
     *     final_paths: array<string, string>,
     *     source_hashes: array<string, string>,
     *     plan_type: string,
     *     edit_count: int,
     *     move_count: int
     * }
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        $map = $this->withRuntimeRoot($map, $root);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Current agent-map source evidence is stale; rebuild the map before applying a rename plan.');
        }

        $type = $plan['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::PLAN_TYPES, true)) {
            throw new RuntimeException('Unsupported agent-map rename plan type.');
        }
        if (($plan['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map rename plan contract version.');
        }
        if (($plan['stale_evidence'] ?? null) !== []) {
            throw new RuntimeException('Rename plan contains stale evidence; rebuild the map and re-plan before applying.');
        }
        if (($plan['blockers'] ?? null) !== []) {
            throw new RuntimeException('Rename plan has semantic blockers; no source was changed.');
        }
        if (($plan['status'] ?? null) === 'review_required') {
            throw new RuntimeException('Rename plan requires explicit review; no source was changed.');
        }
        if (($plan['status'] ?? null) !== 'safe') {
            throw new RuntimeException('Rename plan is not safe; no source was changed.');
        }

        $targetId = $plan['target_id'] ?? null;
        if (!is_string($targetId) || !str_starts_with($targetId, self::TARGET_PREFIX[$type])) {
            throw new RuntimeException('Rename plan target identity does not match its plan type.');
        }

        $provenance = $plan['provenance'] ?? null;
        if (!is_array($provenance)
            || ($provenance['map_digest'] ?? null) !== $map->mapDigest()
            || ($provenance['backend'] ?? null) !== $map->backend
            || ($provenance['analysis_fingerprint'] ?? null) !== $map->fingerprint?->toArray()
        ) {
            throw new RuntimeException('Rename plan does not match the current map identity; rebuild and re-plan.');
        }

        $edits = $plan['edits'] ?? null;
        if (!is_array($edits)) {
            throw new RuntimeException('Rename plan contains an invalid edit list.');
        }
        $moves = $plan['moves'] ?? [];
        if (!is_array($moves)) {
            throw new RuntimeException('Rename plan contains an invalid move list.');
        }
        if ($type !== 'class_rename_plan' && $moves !== []) {
            throw new RuntimeException('Only class_rename_plan may publish file moves.');
        }
        if ($edits === [] && $moves === []) {
            throw new RuntimeException('Safe rename plan contains neither edits nor moves.');
        }

        /** @var array<string, string> $original */
        $original = [];
        /** @var array<string, list<array{int, int, string}>> $byFile */
        $byFile = [];
        /** @var array<string, string> $sourceHashes */
        $sourceHashes = [];

        foreach ($edits as $edit) {
            if (!is_array($edit)) {
                throw new RuntimeException('Rename plan contains an invalid edit.');
            }
            $path = $this->sourcePath($root, $edit['path'] ?? null);
            $content = $original[$path] ??= $this->read($path);
            $start = $edit['start_file_pos'] ?? null;
            $end = $edit['end_file_pos'] ?? null;
            $expected = $edit['expected'] ?? null;
            $replacement = $edit['replacement'] ?? null;
            $sourceHash = $edit['source_sha256'] ?? null;
            if (!is_int($start) || !is_int($end) || $start < 0 || $end < $start
                || !is_string($expected) || $expected === ''
                || !is_string($replacement) || $replacement === ''
                || !is_string($sourceHash)
                || !hash_equals($sourceHash, 'sha256:' . hash('sha256', $content))
                || substr($content, $start, $end - $start + 1) !== $expected
            ) {
                throw new RuntimeException('Rename edit evidence changed before apply; rebuild and re-plan.');
            }
            $this->rememberSourceHash($sourceHashes, $path, $sourceHash);
            $byFile[$path][] = [$start, $end, $replacement];
        }

        /** @var array<string, string> $files */
        $files = [];
        foreach ($byFile as $path => $fileEdits) {
            usort($fileEdits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
            $content = $original[$path];
            $previousStart = strlen($content);
            foreach ($fileEdits as [$start, $end, $replacement]) {
                if ($end >= $previousStart) {
                    throw new RuntimeException('Rename plan contains overlapping edit ranges.');
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
        foreach ($moves as $move) {
            if (!is_array($move)) {
                throw new RuntimeException('Class rename plan contains an invalid move.');
            }
            $from = $this->sourcePath($root, $move['from_path'] ?? null);
            $to = $this->destinationPath($root, $move['to_path'] ?? null);
            $sourceHash = $move['source_sha256'] ?? null;
            if (!is_string($sourceHash)) {
                throw new RuntimeException('Class rename move is missing source hash evidence.');
            }
            $content = $original[$from] ??= $this->read($from);
            if (!hash_equals($sourceHash, 'sha256:' . hash('sha256', $content))) {
                throw new RuntimeException('Class rename move source changed before apply; rebuild and re-plan.');
            }
            $this->rememberSourceHash($sourceHashes, $from, $sourceHash);
            if ($from === $to || isset($finalPaths[$from]) || isset($moveTargets[$to])) {
                throw new RuntimeException('Class rename plan contains duplicate or self-referential file moves.');
            }
            if (file_exists($to) || is_link($to)) {
                throw new RuntimeException('Class rename destination already exists: ' . $this->displayPath($root, $to));
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
                throw new RuntimeException('Rename plan source is missing hash evidence: ' . $this->displayPath($root, $source));
            }
        }

        $uniqueFinals = array_values($finalPaths);
        if (count($uniqueFinals) !== count(array_unique($uniqueFinals))) {
            throw new RuntimeException('Rename plan maps multiple sources onto the same final path.');
        }

        ksort($files, SORT_STRING);
        ksort($finalPaths, SORT_STRING);
        ksort($sourceHashes, SORT_STRING);

        return [
            'files' => $files,
            'final_paths' => $finalPaths,
            'source_hashes' => $sourceHashes,
            'plan_type' => $type,
            'edit_count' => count($edits),
            'move_count' => count($moves),
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
                    throw new RuntimeException('Unable to stage complete rename source: ' . $path);
                }

                $permissions = fileperms($path);
                if (!is_int($permissions) || !chmod($temporary, $permissions & 0o777)) {
                    throw new RuntimeException('Unable to preserve rename source permissions: ' . $path);
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
                    'Rename plan staging failed and cleanup was incomplete: ' . implode('; ', $cleanupFailures),
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
    private function publish(array $staged, array $finalPaths, array $sourceHashes): array
    {
        /** @var array<string, string> $backups */
        $backups = [];
        /** @var array<string, string> $published */
        $published = [];

        try {
            foreach ($finalPaths as $source => $final) {
                if ($source !== $final && (file_exists($final) || is_link($final))) {
                    throw new RuntimeException('Class rename destination appeared before publication: ' . $final);
                }
            }

            foreach ($staged as $source => $temporary) {
                $backup = $this->temporaryPath($source, 'backup');
                if (!$this->move($source, $backup)) {
                    throw new RuntimeException('Unable to back up rename source before publication: ' . $source);
                }
                $backups[$source] = $backup;

                $backupHash = hash_file('sha256', $backup);
                if (!is_string($backupHash) || !hash_equals($sourceHashes[$source], 'sha256:' . $backupHash)) {
                    throw new RuntimeException('Rename source changed during publication precondition capture: ' . $source);
                }

                $final = $finalPaths[$source];
                if ($source !== $final && (file_exists($final) || is_link($final))) {
                    throw new RuntimeException('Class rename destination appeared during publication: ' . $final);
                }
                if (!$this->move($temporary, $final)) {
                    throw new RuntimeException('Unable to publish staged rename source: ' . $final);
                }
                $published[$source] = $final;
            }
        } catch (Throwable $exception) {
            $rollbackFailures = $this->rollback($backups, $published);
            $stageCleanupFailures = $this->cleanupPaths(array_values($staged));
            $failures = [...$rollbackFailures, ...$stageCleanupFailures];
            if ($failures !== []) {
                throw new RuntimeException(
                    'Rename plan publication failed and rollback was incomplete: ' . implode('; ', $failures),
                    0,
                    $exception,
                );
            }

            throw new RuntimeException('Rename plan publication failed; every source file was restored.', 0, $exception);
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
                $failures[] = 'unable to remove partially published rename source ' . $final;
            }
            if (($final === null || $final !== $source) && (is_file($source) || is_link($source)) && !unlink($source)) {
                $failures[] = 'unable to clear original rename source before rollback ' . $source;
            }
            if ((is_file($backup) || is_link($backup)) && !$this->move($backup, $source)) {
                $failures[] = 'unable to restore rename source backup ' . $source;
            }
        }

        return $failures;
    }

    /** @param list<string> $paths @return list<string> */
    private function cleanupPaths(array $paths): array
    {
        $failures = [];
        foreach ($paths as $path) {
            if ((is_file($path) || is_link($path)) && !unlink($path)) {
                $failures[] = 'unable to remove temporary rename file ' . $path;
            }
        }

        return $failures;
    }

    /** @param array<string, string> $hashes */
    private function rememberSourceHash(array &$hashes, string $path, string $sourceHash): void
    {
        if (isset($hashes[$path]) && !hash_equals($hashes[$path], $sourceHash)) {
            throw new RuntimeException('Rename plan contains contradictory source hashes for ' . $path . '.');
        }
        $hashes[$path] = $sourceHash;
    }

    private function sourcePath(string $root, mixed $relative): string
    {
        $relative = $this->relativePath($relative);
        $root = realpath($root);
        $path = is_string($root) ? realpath($root . '/' . $relative) : false;
        if (!is_string($root) || !is_string($path) || !$this->insideRoot($root, $path)) {
            throw new RuntimeException('Rename source path escapes the map root: ' . $relative);
        }

        return $path;
    }

    private function destinationPath(string $root, mixed $relative): string
    {
        $relative = $this->relativePath($relative);
        $root = realpath($root);
        if (!is_string($root)) {
            throw new RuntimeException('Unable to resolve rename map root: ' . $root);
        }
        $parentRelative = dirname($relative);
        $parent = realpath($parentRelative === '.' ? $root : $root . '/' . $parentRelative);
        if (!is_string($parent) || !$this->insideRoot($root, $parent, true)) {
            throw new RuntimeException('Rename destination parent escapes the map root: ' . $relative);
        }

        return rtrim(str_replace('\\', '/', $parent), '/') . '/' . basename($relative);
    }

    private function relativePath(mixed $relative): string
    {
        if (!is_string($relative) || $relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('Rename plan contains an invalid source path.');
        }
        $relative = str_replace('\\', '/', $relative);
        if (str_starts_with($relative, '/') || preg_match('~^[A-Za-z]:/~', $relative) === 1) {
            throw new RuntimeException('Rename plan path must be relative to the map root: ' . $relative);
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Rename plan contains a non-canonical relative path: ' . $relative);
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
        $temporary = $path . '.agent-loop-rename-plan-' . $purpose . '-' . bin2hex(random_bytes(8));
        if (file_exists($temporary) || is_link($temporary)) {
            throw new RuntimeException('Rename plan temporary path already exists: ' . $temporary);
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
            throw new RuntimeException('Unable to start PHP syntax validation for staged rename source.');
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
            throw new RuntimeException('Unable to read rename source: ' . $path);
        }

        return $content;
    }

    private function displayPath(string $root, string $path): string
    {
        $root = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root . '/') ? substr($path, strlen($root) + 1) : $path;
    }
}
