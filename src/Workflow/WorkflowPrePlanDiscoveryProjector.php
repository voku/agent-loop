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
 * Projects optional read-only repository evidence before the first governed PLAN.
 *
 * Ranked Search is only a seed generator. The returned candidates remain
 * explicitly unverified, and this projector never creates/repairs Map state,
 * changes Contract scope, creates a PLAN, or grants approval.
 */
final readonly class WorkflowPrePlanDiscoveryProjector
{
    private const int MAX_CANDIDATES = 5;

    private const int MAX_METHOD_SEEDS = 3;

    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array{
     *   schema_version: '1.0',
     *   evidence_state: 'ranked_unverified',
     *   source: array{task_card: string, revision: string},
     *   query: string,
     *   map_snapshot: string,
     *   search_snapshot: string,
     *   candidates: list<array{
     *     rank: int,
     *     symbol_id: string,
     *     file: string,
     *     line_start: int,
     *     line_end: int,
     *     reasons: list<string>
     *   }>,
     *   structural_context: list<array{
     *     seed_rank: int,
     *     seed_symbol_id: string,
     *     target: string,
     *     slices: list<array{
     *       path: string,
     *       line_start: int,
     *       line_end: int,
     *       roles: list<string>,
     *       reasons: list<string>,
     *       evidence_ids: list<string>,
     *       source_sha256: string
     *     }>,
     *     blind_spots: list<array{
     *       kind: string,
     *       message: string,
     *       path: string|null,
     *       line: int|null,
     *       evidence_ids: list<string>
     *     }>,
     *     omitted: list<array{symbol_id: string, role: string, reason: string}>
     *   }>
     * }|null
     */
    public function project(string $taskId): ?array
    {
        $task = (new WorkflowKanbanContextProjector($this->rootPath))->project($taskId);
        if ($task === null) {
            return null;
        }

        $query = $this->query($task->title, $task->nextAction);
        if ($query === '') {
            return null;
        }

        try {
            $layout = new ProjectLayout($this->rootPath);
            $readiness = (new MapReadinessInspector())->inspect(
                MapArtifactPaths::forProject($this->rootPath, $layout->mapRoot()),
            );
            $map = $readiness->currentMap();
            if (
                !$readiness->rankedSearchReady()
                || $map === null
                || $readiness->mapSnapshot === null
                || $readiness->searchSnapshot === null
            ) {
                return null;
            }

            $search = (new HybridSearch())->search(
                $map,
                SearchIndexStore::openReadOnly($readiness->searchPath),
                $query,
                self::MAX_CANDIDATES,
            );
        } catch (Throwable) {
            // Optional discovery must never turn an existing PLAN route into a
            // preparation/error route. The owner readiness gates above remain
            // the only authority for presenting evidence as current.
            return null;
        }

        $results = is_array($search['results'] ?? null)
            ? array_values(array_filter($search['results'], 'is_array'))
            : [];
        if ($results === []) {
            return null;
        }

        $candidates = [];
        $structuralContext = [];
        $planner = new EditContextPlanner();
        $policy = new EditContextPolicy(maximumFiles: 6, maximumSourceBytes: 16000);
        $expanded = 0;

        foreach ($results as $offset => $result) {
            $candidate = $this->candidate($offset + 1, $result);
            if ($candidate === null) {
                continue;
            }
            $candidates[] = $candidate;

            if ($expanded >= self::MAX_METHOD_SEEDS || !str_starts_with($candidate['symbol_id'], 'method:')) {
                continue;
            }

            $method = $map->resolvedMethodById($candidate['symbol_id']);
            if ($method === null || $this->looksLikeTestPath($method->file->path)) {
                continue;
            }

            $target = $method->owner->fqn . '::' . $method->method->name;
            try {
                $plan = $planner->plan($map, $target, $policy);
            } catch (Throwable) {
                continue;
            }

            $slices = [];
            foreach ($plan->slices as $slice) {
                $slices[] = [
                    'path' => $slice->path,
                    'line_start' => $slice->lineStart,
                    'line_end' => $slice->lineEnd,
                    'roles' => $slice->roles,
                    'reasons' => $slice->reasons,
                    'evidence_ids' => $slice->evidenceIds,
                    'source_sha256' => $slice->sourceSha256,
                ];
            }

            $blindSpots = [];
            foreach ($plan->blindSpots as $blindSpot) {
                $blindSpots[] = [
                    'kind' => $blindSpot->kind,
                    'message' => $blindSpot->message,
                    'path' => $blindSpot->path,
                    'line' => $blindSpot->line,
                    'evidence_ids' => $blindSpot->evidenceIds,
                ];
            }

            $omitted = [];
            foreach ($plan->omitted as $entry) {
                $omitted[] = [
                    'symbol_id' => $entry->symbolId,
                    'role' => $entry->role,
                    'reason' => $entry->reason,
                ];
            }

            $structuralContext[] = [
                'seed_rank' => $offset + 1,
                'seed_symbol_id' => $candidate['symbol_id'],
                'target' => $target,
                'slices' => $slices,
                'blind_spots' => $blindSpots,
                'omitted' => $omitted,
            ];
            ++$expanded;
        }

        if ($candidates === []) {
            return null;
        }

        return [
            'schema_version' => '1.0',
            'evidence_state' => 'ranked_unverified',
            'source' => [
                'task_card' => $task->sourcePath,
                'revision' => $task->sourceRevision,
            ],
            'query' => $query,
            'map_snapshot' => $readiness->mapSnapshot,
            'search_snapshot' => $readiness->searchSnapshot,
            'candidates' => $candidates,
            'structural_context' => $structuralContext,
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array{
     *   rank: int,
     *   symbol_id: string,
     *   file: string,
     *   line_start: int,
     *   line_end: int,
     *   reasons: list<string>
     * }|null
     */
    private function candidate(int $rank, array $result): ?array
    {
        $symbolId = $result['symbol_id'] ?? null;
        $file = $result['file_path'] ?? null;
        $lineStart = $result['start_line'] ?? null;
        $lineEnd = $result['end_line'] ?? null;
        if (!is_string($symbolId) || $symbolId === '' || !is_string($file) || $file === '' || !is_int($lineStart) || !is_int($lineEnd)) {
            return null;
        }

        $reasons = [];
        foreach (is_array($result['reasons'] ?? null) ? $result['reasons'] : [] as $reason) {
            if (is_string($reason) && $reason !== '') {
                $reasons[] = $reason;
            }
        }

        return [
            'rank' => $rank,
            'symbol_id' => $symbolId,
            'file' => $file,
            'line_start' => $lineStart,
            'line_end' => $lineEnd,
            'reasons' => $reasons,
        ];
    }

    private function query(string $title, string $nextAction): string
    {
        $title = $this->boundedText($title, 240);
        $nextAction = $this->boundedText($nextAction, 480);

        return trim($title . ($title !== '' && $nextAction !== '' ? ' ' : '') . $nextAction);
    }

    private function boundedText(string $text, int $maximumCharacters): string
    {
        $normalized = preg_replace('/\\s+/u', ' ', trim($text));
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        return mb_substr($normalized, 0, $maximumCharacters);
    }

    private function looksLikeTestPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\\\', '/', $path));

        return str_starts_with($normalized, 'tests/')
            || str_starts_with($normalized, 'test/')
            || str_contains($normalized, '/tests/')
            || preg_match('/(?:^|\\/)[^\\/]*test\\.php$/', $normalized) === 1;
    }
}
