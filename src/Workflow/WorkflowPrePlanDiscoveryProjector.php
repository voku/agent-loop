<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentMap\Context\EditContextPlan;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
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
        $requestFailure = $this->requestFailure($request);
        if ($requestFailure !== null) {
            return $requestFailure;
        }

        $query = trim($request);
        $artifacts = $this->mapArtifacts();
        $readiness = (new MapReadinessInspector())->inspect($artifacts);
        $map = $readiness->currentMap();
        if ($map === null || $readiness->mapSnapshot === null) {
            return $this->abstained('map_not_current', ['map_state' => $readiness->mapState]);
        }
        if (!$readiness->rankedSearchReady() || $readiness->searchSnapshot === null) {
            return $this->abstained('search_not_current', [
                'map_snapshot' => $readiness->mapSnapshot,
                'search_state' => $readiness->searchState,
            ]);
        }

        $mapSnapshot = $readiness->mapSnapshot;
        $searchSnapshot = $readiness->searchSnapshot;
        $search = $this->searchCandidates(
            $map,
            $readiness->searchPath,
            $query,
            $mapSnapshot,
            $searchSnapshot,
        );
        if (($search['status'] ?? null) !== 'ready') {
            return $search;
        }
        if (!is_array($search['candidates'] ?? null) || !is_string($search['search_mode'] ?? null)) {
            return $this->abstained('search_projection_invalid', ['map_snapshot' => $mapSnapshot]);
        }

        $candidates = array_values($search['candidates']);
        $context = $this->structuralContext($map, $candidates);
        if (!$this->evidenceIsCurrent($artifacts, $mapSnapshot, $searchSnapshot)) {
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
            'search_mode' => $search['search_mode'],
            'candidates' => $candidates,
            ...$context,
        ];
    }

    /** @return array<string, mixed>|null */
    private function requestFailure(string $request): ?array
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

        return null;
    }

    /** @SuppressWarnings("PHPMD.StaticAccess") */
    private function mapArtifacts(): MapArtifactPaths
    {
        $layout = new ProjectLayout($this->rootPath);

        return MapArtifactPaths::forProject($this->rootPath, $layout->mapRoot());
    }

    /**
     * @return array<string, mixed>
     *
     * @SuppressWarnings("PHPMD.StaticAccess")
     */
    private function searchCandidates(
        AgentMapIndex $map,
        string $searchPath,
        string $query,
        string $mapSnapshot,
        string $searchSnapshot,
    ): array {
        try {
            $search = SearchIndexStore::openReadOnly($searchPath);
            $result = (new HybridSearch())->search($map, $search, $query, self::MAX_SEARCH_RESULTS);
        } catch (Throwable) {
            return $this->abstained('search_unreadable', ['map_snapshot' => $mapSnapshot]);
        }

        if (!$this->searchSnapshotsMatch($result, $mapSnapshot, $searchSnapshot)) {
            return $this->abstained('search_snapshot_mismatch', [
                'map_snapshot' => $mapSnapshot,
                'search_snapshot' => $searchSnapshot,
            ]);
        }

        $rows = is_array($result['results'] ?? null) ? array_values($result['results']) : [];
        $candidates = $this->rankedCandidates($rows);
        if ($candidates === []) {
            return $this->abstained('no_ranked_candidates', [
                'map_snapshot' => $mapSnapshot,
                'search_snapshot' => $searchSnapshot,
                'request_sha256' => hash('sha256', $query),
            ]);
        }

        return [
            'status' => 'ready',
            'search_mode' => is_string($result['effective_mode'] ?? null) ? $result['effective_mode'] : 'unknown',
            'candidates' => $candidates,
        ];
    }

    /** @param array<string, mixed> $result */
    private function searchSnapshotsMatch(array $result, string $mapSnapshot, string $searchSnapshot): bool
    {
        return is_string($result['map_snapshot'] ?? null)
            && hash_equals($mapSnapshot, $result['map_snapshot'])
            && is_string($result['search_index_snapshot'] ?? null)
            && hash_equals($searchSnapshot, $result['search_index_snapshot']);
    }

    /** @param list<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function rankedCandidates(array $rows): array
    {
        $candidates = [];
        foreach (array_slice($rows, 0, self::MAX_SEARCH_RESULTS) as $offset => $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = $this->rankedCandidate($row, $offset + 1);
            if ($candidate !== null) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function rankedCandidate(array $row, int $rank): ?array
    {
        $symbolId = is_string($row['symbol_id'] ?? null) ? $row['symbol_id'] : '';
        $filePath = is_string($row['file_path'] ?? null) ? $row['file_path'] : '';
        if ($symbolId === '' || $filePath === '') {
            return null;
        }

        return [
            'rank' => $rank,
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

    /** @param list<array<string, mixed>> $candidates
     * @return array{structural_context: list<array<string, mixed>>, blind_spots: list<array<string, mixed>>, omitted: list<array<string, mixed>>}
     */
    private function structuralContext(AgentMapIndex $map, array $candidates): array
    {
        $structural = [];
        $blindSpots = [];
        $omitted = [];
        $expandedMethods = 0;
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
            if ($expandedMethods >= self::MAX_EXPANDED_METHODS || count($structural) >= self::MAX_STRUCTURAL_SLICES) {
                break;
            }
            $this->appendMethodContext($map, $planner, $policy, $candidate, $structural, $blindSpots, $omitted, $expandedMethods);
        }

        return ['structural_context' => $structural, 'blind_spots' => $blindSpots, 'omitted' => $omitted];
    }

    /**
     * @param array<string, mixed> $candidate
     * @param list<array<string, mixed>> $structural
     * @param list<array<string, mixed>> $blindSpots
     * @param list<array<string, mixed>> $omitted
     */
    private function appendMethodContext(
        AgentMapIndex $map,
        EditContextPlanner $planner,
        EditContextPolicy $policy,
        array $candidate,
        array &$structural,
        array &$blindSpots,
        array &$omitted,
        int &$expandedMethods,
    ): void {
        $symbolId = is_string($candidate['symbol_id'] ?? null) ? $candidate['symbol_id'] : '';
        $rank = is_int($candidate['rank'] ?? null) ? $candidate['rank'] : 0;
        if (!str_starts_with($symbolId, 'method:')) {
            return;
        }

        $method = $map->resolvedMethodById($symbolId);
        if ($method === null) {
            $blindSpots[] = ['candidate_rank' => $rank, 'symbol_id' => $symbolId, 'reason' => 'candidate_method_unresolved'];

            return;
        }

        ++$expandedMethods;
        try {
            $target = $method->owner->fqn . '::' . $method->method->name;
            $plan = $planner->plan($map, $target, $policy);
        } catch (Throwable) {
            $blindSpots[] = ['candidate_rank' => $rank, 'symbol_id' => $symbolId, 'reason' => 'structural_context_unavailable'];

            return;
        }

        $this->appendPlan($plan, $rank, $structural, $blindSpots, $omitted);
    }

    /**
     * @param list<array<string, mixed>> $structural
     * @param list<array<string, mixed>> $blindSpots
     * @param list<array<string, mixed>> $omitted
     */
    private function appendPlan(
        EditContextPlan $plan,
        int $candidateRank,
        array &$structural,
        array &$blindSpots,
        array &$omitted,
    ): void {
        $this->appendSlices($plan, $candidateRank, $structural);
        $this->appendBlindSpots($plan, $candidateRank, $blindSpots);
        $this->appendOmissions($plan, $candidateRank, $omitted);
    }

    /** @param list<array<string, mixed>> $structural */
    private function appendSlices(EditContextPlan $plan, int $candidateRank, array &$structural): void
    {
        foreach ($plan->slices as $slice) {
            if (count($structural) >= self::MAX_STRUCTURAL_SLICES) {
                return;
            }
            $structural[] = [
                'candidate_rank' => $candidateRank,
                'path' => $slice->path,
                'line_start' => $slice->lineStart,
                'line_end' => $slice->lineEnd,
                'roles' => $slice->roles,
                'reasons' => $slice->reasons,
                'evidence_ids' => $slice->evidenceIds,
                'source_sha256' => $slice->sourceSha256,
            ];
        }
    }

    /** @param list<array<string, mixed>> $blindSpots */
    private function appendBlindSpots(EditContextPlan $plan, int $candidateRank, array &$blindSpots): void
    {
        foreach ($plan->blindSpots as $blindSpot) {
            if (count($blindSpots) >= self::MAX_BLIND_SPOTS) {
                return;
            }
            $blindSpots[] = [
                'candidate_rank' => $candidateRank,
                'kind' => $blindSpot->kind,
                'message' => $blindSpot->message,
                'path' => $blindSpot->path,
                'line' => $blindSpot->line,
                'evidence_ids' => $blindSpot->evidenceIds,
            ];
        }
    }

    /** @param list<array<string, mixed>> $omitted */
    private function appendOmissions(EditContextPlan $plan, int $candidateRank, array &$omitted): void
    {
        foreach ($plan->omitted as $omission) {
            if (count($omitted) >= self::MAX_OMITTED_CONTEXT) {
                return;
            }
            $omitted[] = [
                'candidate_rank' => $candidateRank,
                'symbol_id' => $omission->symbolId,
                'role' => $omission->role,
                'reason' => $omission->reason,
            ];
        }
    }

    private function evidenceIsCurrent(MapArtifactPaths $artifacts, string $mapSnapshot, string $searchSnapshot): bool
    {
        $readiness = (new MapReadinessInspector())->inspect($artifacts);

        return $readiness->rankedSearchReady()
            && $readiness->mapSnapshot !== null
            && hash_equals($mapSnapshot, $readiness->mapSnapshot)
            && $readiness->searchSnapshot !== null
            && hash_equals($searchSnapshot, $readiness->searchSnapshot);
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
