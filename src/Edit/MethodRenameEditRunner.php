<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** Applies only a complete, current, safe agent-map method rename plan. */
final readonly class MethodRenameEditRunner implements EditRunner
{
    public function __construct(
        private MethodRenamePlanner $planner = new MethodRenamePlanner(),
        private IndexReader $reader = new IndexReader(),
    ) {
    }

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

        foreach ($files as $path => $content) {
            $this->write($path, $content);
        }

        return new EditRunResult(
            status: 'runner_succeeded',
            exitCode: 0,
            stdout: sprintf("Method rename plan applied %d edit(s) across %d file(s).\n", count($plan['edits']), count($files)),
        );
    }

    /**
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

        return $updated;
    }

    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        if (rtrim(str_replace('\\', '/', $map->root), '/') === rtrim(str_replace('\\', '/', $root), '/')) {
            return $map;
        }

        return new AgentMapIndex($map->schemaVersion, rtrim(str_replace('\\', '/', $root), '/'), $map->backend, $map->files, $map->relations, $map->diagnostics, $map->fingerprint);
    }

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

    private function read(string $path): string
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Unable to read method rename source: ' . $path);
        }

        return $content;
    }

    private function write(string $path, string $content): void
    {
        $temporary = $path . '.tmp-' . getmypid();
        if (file_put_contents($temporary, $content) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Unable to publish method rename source: ' . $path);
        }
    }
}
