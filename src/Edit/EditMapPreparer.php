<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;
use Throwable;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\IO\SourceLanguage;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationException;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;

final readonly class EditMapPreparer implements EditMapProvider
{
    public function __construct(
        private MapPreparationService $maps = new MapPreparationService(),
        private IndexReader $reader = new IndexReader(),
    ) {
    }

    public function prepare(EditRequest $request): AgentMapIndex
    {
        $this->assertPhpTarget($request->target);
        if (is_file($request->mapIndex)) {
            // Checked before any rebuild: rebuilding here is PHP-only and would replace a map
            // that indexes another language with one that silently lacks those files.
            $this->assertPhpOnlyMap($request->mapIndex);
        }

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

    /**
     * Full rebuild of the requested scope through the Map owner; the shared index is only replaced
     * after the new map was built completely.
     */
    private function build(EditRequest $request): AgentMapIndex
    {
        try {
            return $this->maps->rebuild(new MapPreparationRequest(
                root: $request->mapRoot,
                indexPath: $request->mapIndex,
                outputPath: $request->mapIndex,
                format: str_ends_with(strtolower($request->mapIndex), '.toon') ? 'toon' : 'json',
                paths: $request->mapPaths,
                pathsProvided: true,
                scanPaths: [],
                scanPathsProvided: false,
                excludes: $request->mapExcludes,
                excludesProvided: true,
                backend: 'auto',
                phpStanConfig: $request->phpStanConfiguration,
                phpStanMemoryLimit: $request->phpStanMemoryLimit,
                artifacts: MapArtifactPaths::forProject($request->mapRoot),
            ))->index;
        } catch (MapPreparationException $exception) {
            throw new RuntimeException($exception->getMessage() . ' Recovery: ' . $exception->recoveryCommand, 0, $exception);
        }
    }

    /**
     * Deterministic edit is a PHP method contract. agent-map names a TypeScript/JavaScript symbol
     * `<file>#<Name>`; such a target is refused here instead of failing later as "not found".
     */
    private function assertPhpTarget(string $target): void
    {
        $separator = strpos($target, '#');
        $language = $separator === false ? null : SourceLanguage::ofPath(substr($target, 0, $separator));
        if ($language !== null && $language !== SourceLanguage::PHP) {
            throw new RuntimeException(
                'agent-loop edit supports PHP methods only; "' . $target . '" names a ' . $language
                . ' symbol. Edit that file directly within the governed Contract scope instead.',
            );
        }
    }

    private function assertPhpOnlyMap(string $mapIndex): void
    {
        try {
            $backend = $this->reader->readSections($mapIndex, ['files'])->backend;
        } catch (Throwable) {
            return; // An unreadable map stays the existing read/rebuild path's to report.
        }

        $languages = AgentMapBuilder::languagesForBackend($backend);
        if ($languages !== [SourceLanguage::PHP]) {
            throw new RuntimeException(
                'agent-loop edit supports PHP-only maps; ' . $mapIndex . ' indexes ' . implode(', ', $languages)
                . ' (backend "' . $backend . '"). Its map refresh and method resolution do not cover those files.'
                . ' Pass --map-index with a separate path to let the edit build its own PHP-only map,'
                . ' or edit the file directly within the governed Contract scope.',
            );
        }
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
