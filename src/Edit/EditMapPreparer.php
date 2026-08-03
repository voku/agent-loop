<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;

final readonly class EditMapPreparer implements EditMapProvider
{
    public function __construct(
        private AgentMapBuilder $builder = new AgentMapBuilder(),
        private IndexReader $reader = new IndexReader(),
        private IndexWriter $writer = new IndexWriter(),
    ) {
    }

    public function prepare(EditRequest $request): AgentMapIndex
    {
        if ($request->forceMapRebuild || !is_file($request->mapIndex)) {
            if (!$request->allowMapRebuild) {
                throw new RuntimeException('Agent map is missing and automatic rebuilding is disabled: ' . $request->mapIndex);
            }
            $map = $this->build($request);
        } else {
            $map = $this->reader->read($request->mapIndex);
        }

        $runtimeMap = $this->withRuntimeRoot($map, $request->mapRoot);
        $stale = $runtimeMap->staleEntries();
        if ($stale !== []) {
            if (!$request->allowMapRebuild) {
                throw new RuntimeException($this->staleMessage('Agent map is stale and automatic rebuilding is disabled.', $stale));
            }
            $runtimeMap = $this->withRuntimeRoot($this->build($request), $request->mapRoot);
            $stale = $runtimeMap->staleEntries();
            if ($stale !== []) {
                throw new RuntimeException($this->staleMessage('Agent map remains stale after rebuilding.', $stale));
            }
        }

        // Resolve before publishing recall artifacts so missing, ambiguous, and
        // conflicted targets fail at the deterministic repository boundary.
        $runtimeMap->resolveMethod($request->target);

        return $runtimeMap;
    }

    private function build(EditRequest $request): AgentMapIndex
    {
        $map = $this->builder->build(
            root: $request->mapRoot,
            paths: $request->mapPaths,
            excludes: $request->mapExcludes,
            phpStanConfiguration: $request->phpStanConfiguration,
        );
        $this->writer->write($map, $request->mapIndex);

        return $map;
    }

    private function withRuntimeRoot(AgentMapIndex $map, string $root): AgentMapIndex
    {
        if (rtrim(str_replace('\\', '/', $map->root), '/') === rtrim(str_replace('\\', '/', $root), '/')) {
            return $map;
        }

        return new AgentMapIndex(
            schemaVersion: $map->schemaVersion,
            root: rtrim(str_replace('\\', '/', $root), '/'),
            backend: $map->backend,
            files: $map->files,
            relations: $map->relations,
            diagnostics: $map->diagnostics,
            fingerprint: $map->fingerprint,
        );
    }

    /**
     * @param list<array{path: string, reason: string}> $stale
     */
    private function staleMessage(string $message, array $stale): string
    {
        $lines = [$message];
        foreach (array_slice($stale, 0, 20) as $entry) {
            $lines[] = '- ' . $entry['path'] . ' (' . $entry['reason'] . ')';
        }
        if (count($stale) > 20) {
            $lines[] = '- … and ' . (count($stale) - 20) . ' more';
        }

        return implode("\n", $lines);
    }
}
