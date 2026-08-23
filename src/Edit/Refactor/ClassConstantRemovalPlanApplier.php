<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use Closure;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Edit\EditRunResult;
use voku\AgentMap\Index\AgentMapIndex;

/** Applies one governed class-constant-removal plan through a fail-closed transactional PHP edit boundary. */
final readonly class ClassConstantRemovalPlanApplier
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

    /** @param array<string, mixed> $plan */
    public function apply(array $plan, AgentMapIndex $map, string $root): EditRunResult
    {
        $document = ClassConstantRemovalPlanDocument::fromArray($plan);
        $prepared = $this->preflightDocument($document, $map, $root);
        $staged = $this->stageAndValidate($prepared['files']);

        try {
            $current = $this->preflightDocument($document, $map, $root);
            if ($current['files'] !== $prepared['files'] || $current['source_hashes'] !== $prepared['source_hashes']) {
                throw new RuntimeException('Class-constant removal source evidence changed while staged; no source was changed.');
            }
            $warnings = $this->publish($staged, $prepared['source_hashes']);
        } catch (Throwable $exception) {
            $cleanupFailures = $this->cleanupPaths(array_values($staged));
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Class-constant removal failed and staged-file cleanup was incomplete: ' . implode('; ', $cleanupFailures),
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
                "class_constant_removal_plan applied %d deletion edit(s) across %d source file(s).\n",
                $prepared['edit_count'],
                count($prepared['files']),
            ),
            stderr: $warnings === [] ? '' : "Class-constant removal completed with cleanup warning(s):\n- " . implode("\n- ", $warnings),
        );
    }

    /**
     * @param array<string, mixed> $plan
     * @return array{files: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int}
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        return $this->preflightDocument(ClassConstantRemovalPlanDocument::fromArray($plan), $map, $root);
    }

    /**
     * @return array{files: array<string, string>, source_hashes: array<string, string>, plan_type: string, edit_count: int, move_count: int}
     */
    private function preflightDocument(ClassConstantRemovalPlanDocument $plan, AgentMapIndex $map, string $root): array
    {
        $map = $this->withRuntimeRoot($map, $root);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Current agent-map source evidence is stale; rebuild the map before applying a class-constant removal plan.');
        }
        $plan->provenance->assertMatches($map);

        /** @var array<string, string> $original */
        $original = [];
        /** @var array<string, list<array{int, int}>> $byFile */
        $byFile = [];
        /** @var array<string, string> $sourceHashes */
        $sourceHashes = [];

        foreach ($plan->edits as $edit) {
            $path = $this->sourcePath($root, $edit->path);
            $content = $original[$path] ??= $this->read($path);
            if (
                !hash_equals($edit->sourceSha256, 'sha256:' . hash('sha256', $content))
                || substr($content, $edit->startFilePos, $edit->endFilePos - $edit->startFilePos + 1) !== $edit->expected
            ) {
                throw new RuntimeException('Class-constant removal edit evidence changed before apply; rebuild and re-plan.');
            }
            $this->rememberSourceHash($sourceHashes, $path, $edit->sourceSha256);
            $byFile[$path][] = [$edit->startFilePos, $edit->endFilePos];
        }

        /** @var array<string, string> $files */
        $files = [];
        foreach ($byFile as $path => $fileEdits) {
            usort($fileEdits, static fn (array $left, array $right): int => $right[0] <=> $left[0]);
            $content = $original[$path];
            $previousStart = strlen($content);
            foreach ($fileEdits as [$start, $end]) {
                if ($end >= $previousStart) {
                    throw new RuntimeException('Class-constant removal plan contains overlapping edit ranges.');
                }
                $content = substr($content, 0, $start) . substr($content, $end + 1);
                $previousStart = $start;
            }
            $files[$path] = $content;
        }

        ksort($files, SORT_STRING);
        ksort($sourceHashes, SORT_STRING);

        return [
            'files' => $files,
            'source_hashes' => $sourceHashes,
            'plan_type' => 'class_constant_removal_plan',
            'edit_count' => count($plan->edits),
            'move_count' => 0,
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
                    throw new RuntimeException('Unable to stage complete class-constant-removal source: ' . $path);
                }
                $permissions = fileperms($path);
                if (!is_int($permissions) || !chmod($temporary, $permissions & 0o777)) {
                    throw new RuntimeException('Unable to preserve class-constant-removal source permissions: ' . $path);
                }
            }

            foreach ($staged as $path => $temporary) {
                $lint = $this->lint($temporary);
                if ($lint['exit_code'] !== 0) {
                    $detail = trim($lint['stderr'] !== '' ? $lint['stderr'] : $lint['stdout']);
                    throw new RuntimeException(
                        'Rewritten PHP failed syntax validation before class-constant-removal publication: '
                        . $path
                        . ($detail === '' ? '' : ' (' . $detail . ')'),
                    );
                }
            }
        } catch (Throwable $exception) {
            $cleanupFailures = $this->cleanupPaths(array_values($staged));
            if ($cleanupFailures !== []) {
                throw new RuntimeException(
                    'Class-constant-removal staging failed and cleanup was incomplete: ' . implode('; ', $cleanupFailures),
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
     * @param array<string, string> $sourceHashes absolute source path => expected sha256
     * @return list<string>
     */
    private function publish(array $staged, array $sourceHashes): array
    {
        /** @var array<string, string> $backups */
        $backups = [];
        /** @var array<string, string> $published */
        $published = [];

        try {
            foreach ($staged as $source => $temporary) {
                $backup = $this->temporaryPath($source, 'backup');
                if (!$this->move($source, $backup)) {
                    throw new RuntimeException('Unable to back up class-constant-removal source before publication: ' . $source);
                }
                $backups[$source] = $backup;

                $backupHash = hash_file('sha256', $backup);
                if (!is_string($backupHash) || !hash_equals($sourceHashes[$source], 'sha256:' . $backupHash)) {
                    throw new RuntimeException('Class-constant-removal source changed during publication precondition capture: ' . $source);
                }
                if (!$this->move($temporary, $source)) {
                    throw new RuntimeException('Unable to publish staged class-constant-removal source: ' . $source);
                }
                $published[$source] = $source;
            }
        } catch (Throwable $exception) {
            $rollbackFailures = $this->rollback($backups, $published);
            $stageCleanupFailures = $this->cleanupPaths(array_values($staged));
            $failures = [...$rollbackFailures, ...$stageCleanupFailures];
            if ($failures !== []) {
                throw new RuntimeException(
                    'Class-constant-removal publication failed and rollback was incomplete: ' . implode('; ', $failures),
                    0,
                    $exception,
                );
            }
            throw new RuntimeException('Class-constant-removal publication failed; every source file was restored.', 0, $exception);
        }

        return $this->cleanupPaths(array_values($backups));
    }

    /**
     * @param array<string, string> $backups
     * @param array<string, string> $published
     * @return list<string>
     */
    private function rollback(array $backups, array $published): array
    {
        $failures = [];
        foreach (array_reverse($backups, true) as $source => $backup) {
            if (isset($published[$source]) && (is_file($source) || is_link($source)) && !unlink($source)) {
                $failures[] = 'unable to remove partially published class-constant-removal source ' . $source;
            }
            if ((is_file($backup) || is_link($backup)) && !$this->move($backup, $source)) {
                $failures[] = 'unable to restore class-constant-removal source backup ' . $source;
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
                $failures[] = 'unable to remove temporary class-constant-removal file ' . $path;
            }
        }

        return $failures;
    }

    /** @param array<string, string> $hashes */
    private function rememberSourceHash(array &$hashes, string $path, string $sourceHash): void
    {
        if (isset($hashes[$path]) && !hash_equals($hashes[$path], $sourceHash)) {
            throw new RuntimeException('Class-constant removal plan contains contradictory source hashes for ' . $path . '.');
        }
        $hashes[$path] = $sourceHash;
    }

    private function sourcePath(string $root, string $relative): string
    {
        $relative = $this->relativePath($relative);
        $realRoot = realpath($root);
        $path = is_string($realRoot) ? realpath($realRoot . '/' . $relative) : false;
        if (!is_string($realRoot) || !is_string($path) || !$this->insideRoot($realRoot, $path)) {
            throw new RuntimeException('Class-constant removal source path escapes the map root: ' . $relative);
        }

        return $path;
    }

    private function relativePath(string $relative): string
    {
        if ($relative === '' || str_contains($relative, "\0")) {
            throw new RuntimeException('Class-constant removal plan contains an invalid source path.');
        }
        $relative = str_replace('\\', '/', $relative);
        if (str_starts_with($relative, '/') || preg_match('~^[A-Za-z]:/~', $relative) === 1) {
            throw new RuntimeException('Class-constant removal plan path must be relative to the map root: ' . $relative);
        }
        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Class-constant removal plan contains a non-canonical relative path: ' . $relative);
            }
        }

        return $relative;
    }

    private function insideRoot(string $root, string $path): bool
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $path = str_replace('\\', '/', $path);

        return str_starts_with($path, $root . '/');
    }

    private function temporaryPath(string $path, string $purpose): string
    {
        $temporary = $path . '.agent-loop-class-constant-removal-' . $purpose . '-' . bin2hex(random_bytes(8));
        if (file_exists($temporary) || is_link($temporary)) {
            throw new RuntimeException('Class-constant-removal temporary path already exists: ' . $temporary);
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
            throw new RuntimeException('Unable to start PHP syntax validation for staged class-constant-removal source.');
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
            throw new RuntimeException('Unable to read class-constant-removal source: ' . $path);
        }

        return $content;
    }
}
