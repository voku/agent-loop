<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentMap\Search\HybridSearch;
use voku\AgentMap\Search\SearchIndexStore;

/**
 * Projects bounded, read-only Map/Search evidence beside an initial PLAN action.
 *
 * Search leads stay unverified navigation evidence. This projection never
 * prepares owner artifacts or changes Contract scope or lifecycle authority.
 */
final readonly class WorkflowPrePlanDiscoveryProjector
{
    private const int MAX_REQUEST_BYTES = 4000;
    private const int MAX_SEARCH_RESULTS = 5;
    private const int MAX_EXPANDED_METHODS = 3;
    private const int MAX_STRUCTURAL_SLICES = 12;
    private const int MAX_BLIND_SPOTS = 12;
    private const int MAX_OMITTED_CONTEXT = 12;

    public function __construct(private string $rootPath)
    {
    }

    /** @return array<string, mixed> */
    public function project(string $request): array
    {
        $query = trim($request);
        if ($query === '') {
            return $this->abstained('request_empty');
        }
        if (str_contains($query, "\0") || preg_match('//u', $query) !== 1) {
            return $this->abstained('request_invalid');
        }
        if (strlen($query) > self::MAX_REQUEST_BYTES) {
            return $this->abstained('request_too_large', [
                'request_bytes' => strlen($query),
                'request_sha256' => hash('sha256', $query),
            ]);
        }

        $layout = new ProjectLayout($this->rootPath);
        $artifacts = MapArtifactPaths::forProject($this->rootPath, $layout->mapRoot());
        $readiness = (new MapReadinessInspector())->inspect($artifacts);
        $map = $readiness->currentMap();
        if ($map === null || $readiness->mapSnapshot === null) {
            return $this->abstained('map_not_current', [
                'map_state' => $readiness->mapState,
            ]);
        }
        if (!$readiness->rankedSearchReady()) {
            return $this->abstained('search_not_current', [
                'map_snapshot' => $readiness->mapSnapshot,
                'search_state' => $readiness->searchState,
            ]);
        }

        try {
            $search = SearchIndexStore::openReadOnly($readiness->searchPath);
            $result = (new HybridSearch())->search($map, $search, $query, self::MAX_SEARCH_RESULTS);
        } catch (Throwable) {
            return $this->abstained('search_unreadable', [
                'map_snapshot' => $readiness->mapSnapshot,
            ]);
        }

        $mapSnapshot = $readiness->mapSnapshot;
        $searchSnapshot = $readiness->searchSnapshot;
        if (!is_string($result['map_snapshot'] ?? null)
            || !hash_equals($mapSnapshot, $result['map_snapshot'])
            || !is_string($result['search_index_snapshot'] ?? null)
            || $searchSnapshot === null
            || !hash_equals($searchSnapshot, $result['search_index_snapshot'])
        ) {
            return $this->abstained('search_snapshot_mismatch', [
                'map_snapshot' => $mapSnapshot,
                'search_snapshot' => $searchSnapshot,
            ]);
        }

        $rows = is_array($result['results'] ?? null) ? $result['results'] : [];
        $candidates = [];
        foreach (array_slice($rows, 0, self::MAX_SEARCH_RESULTS) as $offset => $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbolId = is_string($row['symbol_id'] ?? null) ? $row['symbol_id'] : '';
            $filePath = is_string($row['file_path'] ?? null) ? $row['file_path'] : '';
            if ($symbolId === '' || $filePath === '') {
                continue;
            }
            $candidates[] = [
                'rank' => $offset + 1,
                'status' => 'ranked_unverified',
                'symbol_id' => $symbolId,
                'file_path' => $filePath,
                'start_line' => is_int($row['start_line'] ?? null) ? $row['start_line'] : null,
                'end_line' => is_int($row['end_line'] ?? null) ? $row['end_line'] : null,
                'score' => is_float($row['rrf_score'] ?? null) || is_int($row['rrf_score'] ?? null)
                    ? (float) $row['rrf_score']
                    : null,
                'channel_ranks' => is_array($row['channel_ranks'] ?? null) ? $row['channel_ranks'] : [],
                'reasons' => is_array($row['reasons'] ?? null) ? array_values(array_filter($row['reasons'], 'is_string')) : [],
            ];
        }

        if ($candidates === []) {
            return $this->abstained('no_ranked_candidates', [
                'map_snapshot' => $mapSnapshot,
                'search_snapshot' => $searchSnapshot,
                'request_sha256' => hash('sha256', $query),
            ]);
        }

        $structuralContext = [];
        $blindSpots = [];
        $omitted = [];
        $expandedMethods = 0;
        $sliceCount = 0;
        $planner = new EditContextPlanner();
        $policy = new EditContextPolicy(
            maximumSourceBytes: 8000,
            maximumFiles: 4,
            maximumCallers: 4,
            maximumCallees: 4,
            maximumTests: 4,
            maximumTypeDefinitions: 4,
        );

        foreach ($candidates as $candidate) {
            if ($expandedMethods >= self::MAX_EXPANDED_METHODS
                || $sliceCount >= self::MAX_STRUCTURAL_SLICES
                || !str_starts_with($candidate['symbol_id'], 'method:')
            ) {
                continue;
            }
            $method = $map->resolvedMethodById($candidate['symbol_id']);
            if ($method === null) {
                $blindSpots[] = [
                    'candidate_rank' => $candidate['rank'],
                    'symbol_id' => $candidate['symbol_id'],
                    'reason' => 'candidate_method_unresolved',
                ];
                continue;
            }
            ++$expandedMethods;
            try {
                $target = $method->owner->fqn . '::' . $method->method->name;
                $plan = $planner->plan($map, $target, $policy);
            } catch (Throwable) {
                $blindSpots[] = [
                    'candidate_rank' => $candidate['rank'],
                    'symbol_id' => $candidate['symbol_id'],
                    'reason' => 'structural_context_unavailable',
                ];
                continue;
            }

            foreach ($plan->slices as $slice) {
                if ($sliceCount >= self::MAX_STRUCTURAL_SLICES) {
                    break;
                }
                $structuralContext[] = [
                    'candidate_rank' => $candidate['rank'],
                    'path' => $slice->path,
                    'line_start' => $slice->lineStart,
                    'line_end' => $slice->lineEnd,
                    'roles' => $slice->roles,
                    'reasons' => $slice->reasons,
                    'evidence_ids' => $slice->evidenceIds,
                    'source_sha256' => $slice->sourceSha256,
                ];
                ++$sliceCount;
            }
            foreach ($plan->blindSpots as $blindSpot) {
                if (count($blindSpots) >= self::MAX_BLIND_SPOTS) {
                    break;
                }
                $blindSpots[] = [
                    'candidate_rank' => $candidate['rank'],
                    'kind' => $blindSpot->kind,
                    'message' => $blindSpot->message,
                    'path' => $blindSpot->path,
                    'line' => $blindSpot->line,
                    'evidence_ids' => $blindSpot->evidenceIds,
                ];
            }
            foreach ($plan->omitted as $omission) {
                if (count($omitted) >= self::MAX_OMITTED_CONTEXT) {
                    break;
                }
                $omitted[] = [
                    'candidate_rank' => $candidate['rank'],
                    'symbol_id' => $omission->symbolId,
                    'role' => $omission->role,
                    'reason' => $omission->reason,
                ];
            }
        }

        // Recheck after search and context planning so a source edit during the
        // projection cannot make an earlier readiness observation look current.
        $finalReadiness = (new MapReadinessInspector())->inspect($artifacts);
        if (!$finalReadiness->rankedSearchReady()
            || $finalReadiness->mapSnapshot === null
            || $finalReadiness->searchSnapshot === null
            || !hash_equals($mapSnapshot, $finalReadiness->mapSnapshot)
            || !hash_equals($searchSnapshot, $finalReadiness->searchSnapshot)
        ) {
            return $this->abstained('evidence_became_stale', [
                'map_snapshot' => $mapSnapshot,
                'search_snapshot' => $searchSnapshot,
            ]);
        }

        return [
            'owner' => 'agent-map',
            'status' => 'ready',
            'evidence_status' => 'current_navigation_evidence',
            'authority' => 'none',
            'query_sha256' => hash('sha256', $query),
            'map_snapshot' => $mapSnapshot,
            'search_snapshot' => $searchSnapshot,
            'search_mode' => is_string($result['effective_mode'] ?? null) ? $result['effective_mode'] : 'unknown',
            'candidates' => $candidates,
            'structural_context' => $structuralContext,
            'blind_spots' => $blindSpots,
            'omitted' => $omitted,
        ];
    }

    /** @param array<string, mixed> $details
     * @return array<string, mixed>
     */
    private function abstained(string $reason, array $details = []): array
    {
        return [
            'owner' => 'agent-map',
            'status' => 'abstained',
            'reason' => $reason,
            'authority' => 'none',
            ...$details,
        ];
    }
}
