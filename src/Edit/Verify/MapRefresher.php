<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

use Throwable;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Prepare\MapPreparationRequest;
use voku\AgentMap\Prepare\MapPreparationService;

/**
 * Brings the edit's own map index up to date with the post-edit working tree.
 *
 * Deliberately bundle-local. Refreshing the shared `.agent-loop/map/php-symbols.json` while grading
 * would mutate state the next task reads, which makes verification a side effect rather than an
 * observation. agent-map reads the shared index and writes the refreshed result, with its
 * companions, only to the bundle's own output path; what changed, was added or was deleted is
 * decided by the Map owner, so a deleted file is pruned rather than reported stale and the
 * `target_resolvable` gate is the one that fails when the edit removed the target.
 *
 * A refresh that cannot run reports itself as unavailable; the gates then read `not_run`, and a
 * required gate that did not run fails the verification. Nothing here is allowed to invent a pass.
 */
final readonly class MapRefresher
{
    public const FILE_NAME = 'post-edit-map.json';

    public function __construct(private MapPreparationService $maps = new MapPreparationService())
    {
    }

    /**
     * @return array{available: bool, index: ?string, stale: list<string>, detail: string}
     */
    public function refresh(VerificationBundle $bundle, string $projectRoot): array
    {
        $recorded = $this->request($bundle);
        $source = $this->stringField($recorded, 'map_index');
        if ($source === null || !is_file($source)) {
            return ['available' => false, 'index' => null, 'stale' => [], 'detail' => 'The edit bundle records no map index to refresh.'];
        }

        $index = $bundle->directory . '/' . self::FILE_NAME;

        // The scope the edit was compiled against, as recorded in request.json: refreshing outside it
        // would let an excluded path change symbol resolution.
        $paths = $this->stringList($recorded, 'map_paths');
        $excludes = $this->stringList($recorded, 'map_excludes');

        try {
            $prepared = $this->maps->prepare(new MapPreparationRequest(
                root: $projectRoot,
                indexPath: $source,
                outputPath: $index,
                format: 'json',
                paths: $paths === [] ? ['.'] : $paths,
                pathsProvided: $paths !== [],
                scanPaths: [],
                scanPathsProvided: false,
                excludes: $excludes,
                excludesProvided: array_key_exists('map_excludes', $recorded),
                backend: 'auto',
                phpStanConfig: $this->stringField($recorded, 'phpstan_configuration'),
                phpStanMemoryLimit: $this->stringField($recorded, 'phpstan_memory_limit'),
                artifacts: MapArtifactPaths::forProject($projectRoot),
            ));

            $stale = [];
            foreach ($prepared->index->staleEntries() as $entry) {
                $stale[] = $entry['path'];
            }

            return ['available' => true, 'index' => $index, 'stale' => $stale, 'detail' => 'Post-edit map refreshed by agent-map into the bundle.'];
        } catch (Throwable $exception) {
            return ['available' => false, 'index' => null, 'stale' => [], 'detail' => 'Post-edit map refresh failed: ' . $exception->getMessage()];
        }
    }

    /** @return array<string, mixed> */
    private function request(VerificationBundle $bundle): array
    {
        $request = $bundle->directory . '/request.json';
        if (!is_file($request)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($request), true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /** @param array<string, mixed> $recorded */
    private function stringField(array $recorded, string $key): ?string
    {
        $value = $recorded[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $recorded
     *
     * @return list<string>
     */
    private function stringList(array $recorded, string $key): array
    {
        $list = [];
        foreach (is_array($recorded[$key] ?? null) ? $recorded[$key] : [] as $value) {
            if (is_string($value) && $value !== '') {
                $list[] = $value;
            }
        }

        return $list;
    }
}
