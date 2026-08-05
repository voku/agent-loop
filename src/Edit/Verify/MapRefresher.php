<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

use Throwable;
use voku\AgentMap\Index\AgentMapBuilder;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Index\IndexWriter;

/**
 * Brings the edit's own map index up to date with the post-edit working tree.
 *
 * Deliberately bundle-local. Refreshing the shared `.agent-map/php-symbols.json` while grading
 * would mutate state the next task reads, which makes verification a side effect rather than an
 * observation. The bundle map is also the narrow one the edit was compiled against, so refreshing
 * it costs the changed files, not the repository.
 *
 * A refresh that cannot run reports itself as unavailable; the gates then read `not_run`, and a
 * required gate that did not run fails the verification. Nothing here is allowed to invent a pass.
 */
final readonly class MapRefresher
{
    public const FILE_NAME = 'post-edit-map.json';

    /**
     * @return array{available: bool, index: ?string, stale: list<string>, detail: string}
     */
    public function refresh(VerificationBundle $bundle, string $projectRoot): array
    {
        $source = $this->indexPath($bundle);
        if ($source === null || !is_file($source)) {
            return ['available' => false, 'index' => null, 'stale' => [], 'detail' => 'The edit bundle records no map index to refresh.'];
        }

        // Copied into the bundle first: an edit run that used the shared default index would
        // otherwise have verification rewrite the very file the next task reads.
        $index = $bundle->directory . '/' . self::FILE_NAME;
        if (!is_file($index) && !copy($source, $index)) {
            return ['available' => false, 'index' => null, 'stale' => [], 'detail' => 'Unable to copy the map index into the bundle: ' . $source];
        }

        try {
            $previous = (new IndexReader())->read($index);
            $changed = [];
            foreach ($previous->staleEntries() as $entry) {
                if ($entry['reason'] !== 'missing') {
                    $changed[] = $entry['path'];
                }
            }

            if ($changed !== []) {
                $refreshed = (new AgentMapBuilder())->build($projectRoot, $changed, [], null, null, $previous);
                (new IndexWriter())->write($refreshed, $index);
                $previous = $refreshed;
            }

            $stale = [];
            foreach ($previous->staleEntries() as $entry) {
                $stale[] = $entry['path'];
            }

            return ['available' => true, 'index' => $index, 'stale' => $stale, 'detail' => 'Post-edit map refreshed in place.'];
        } catch (Throwable $exception) {
            return ['available' => false, 'index' => null, 'stale' => [], 'detail' => 'Post-edit map refresh failed: ' . $exception->getMessage()];
        }
    }

    private function indexPath(VerificationBundle $bundle): ?string
    {
        $request = $bundle->directory . '/request.json';
        if (!is_file($request)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($request), true);
        if (!is_array($decoded)) {
            return null;
        }

        $index = $decoded['map_index'] ?? null;

        return is_string($index) && $index !== '' ? $index : null;
    }
}
