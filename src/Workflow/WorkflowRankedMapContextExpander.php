<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;
use voku\AgentMap\Context\EditContextPlanner;
use voku\AgentMap\Context\EditContextPolicy;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;

/**
 * Turns ranked Recall navigation leads into bounded, read-only structural context.
 *
 * Ranked search remains unverified evidence. This class never changes its order,
 * widens Contract scope, rebuilds the map, or writes any workflow state.
 */
final readonly class WorkflowRankedMapContextExpander
{
    private const int MAX_METHOD_SEEDS = 3;

    public function __construct(private string $rootPath)
    {
    }

    /** @param array<string, mixed> $fact */
    public function add(WorkflowContextBudget $budget, array $fact): void
    {
        $payload = is_array($fact['payload'] ?? null) ? $fact['payload'] : [];
        if (($payload['status'] ?? null) !== 'ranked' || !is_array($payload['results'] ?? null)) {
            return;
        }

        $results = array_values(array_filter($payload['results'], 'is_array'));
        if ($results === []) {
            return;
        }

        $budget->section('Candidate map leads (ranked, unverified)');
        foreach ($results as $offset => $result) {
            $budget->add('candidate_navigation', $this->leadLine($offset + 1, $result));
        }

        try {
            $index = $this->currentMap();
        } catch (RuntimeException $exception) {
            $budget->skip('agent-map candidate expansion: ' . $exception->getMessage());

            return;
        }

        if ($index->fingerprint === null) {
            $budget->skip('agent-map candidate expansion: current map has no source fingerprint');

            return;
        }

        $recallSnapshot = is_string($payload['map_snapshot'] ?? null) ? trim($payload['map_snapshot']) : '';
        $currentSnapshot = $index->fingerprint->sourceDigest;
        if ($recallSnapshot === '' || $currentSnapshot === '' || $recallSnapshot !== $currentSnapshot) {
            $budget->skip(sprintf(
                'agent-map candidate expansion: map snapshot mismatch (recall %s, current %s)',
                $recallSnapshot === '' ? 'missing' : $recallSnapshot,
                $currentSnapshot === '' ? 'missing' : $currentSnapshot,
            ));

            return;
        }

        $planner = new EditContextPlanner();
        $policy = new EditContextPolicy(maximumFiles: 6, maximumSourceBytes: 16000);
        $addedSection = false;
        $expanded = 0;
        foreach ($results as $offset => $result) {
            $symbolId = is_string($result['symbol_id'] ?? null) ? $result['symbol_id'] : '';
            if (!str_starts_with($symbolId, 'method:')) {
                continue;
            }

            $method = $index->resolvedMethodById($symbolId);
            if ($method === null) {
                $budget->skip('agent-map candidate expansion: unresolved method seed ' . $symbolId);
                continue;
            }

            if ($this->looksLikeTestPath($method->file->path)) {
                $callees = [];
                foreach ($index->outgoing($method->id, 'calls') as $relation) {
                    foreach ($relation->targetIds as $targetId) {
                        $callee = $index->resolvedMethodById($targetId);
                        if ($callee === null || $this->looksLikeTestPath($callee->file->path)) {
                            continue;
                        }
                        $callees[$callee->id] = $callee;
                    }
                }
                foreach ($callees as $callee) {
                    if (!$addedSection) {
                        $budget->section('Candidate structural context (unverified)');
                        $addedSection = true;
                    }
                    $budget->add('candidate_context', sprintf(
                        '  rank %d test evidence calls %s — %s:%d-%d',
                        $offset + 1,
                        $callee->owner->fqn . '::' . $callee->method->name,
                        $callee->file->path,
                        $callee->method->lineStart,
                        $callee->method->lineEnd,
                    ));
                }

                continue;
            }
            if ($expanded >= self::MAX_METHOD_SEEDS) {
                continue;
            }

            $target = $method->owner->fqn . '::' . $method->method->name;
            try {
                $plan = $planner->plan($index, $target, $policy);
            } catch (Throwable $exception) {
                $budget->skip('agent-map candidate expansion: ' . $exception->getMessage());
                continue;
            }

            if (!$addedSection) {
                $budget->section('Candidate structural context (unverified)');
                $addedSection = true;
            }
            $budget->add('candidate_context', sprintf('  rank %d seed %s', $offset + 1, $target));
            foreach ($plan->slices as $slice) {
                $budget->add('candidate_context', sprintf(
                    '    %s:%d-%d [%s] %s',
                    $slice->path,
                    $slice->lineStart,
                    $slice->lineEnd,
                    implode(',', $slice->roles),
                    implode('; ', $slice->reasons),
                ));
            }
            foreach ($plan->blindSpots as $blindSpot) {
                $budget->add('candidate_context', '    blind spot: ' . $blindSpot->message);
            }
            foreach ($plan->omitted as $omitted) {
                $budget->add('candidate_context', sprintf(
                    '    omitted %s [%s]: %s',
                    $omitted->symbolId,
                    $omitted->role,
                    $omitted->reason,
                ));
            }
            ++$expanded;
        }
    }

    private function currentMap(): AgentMapIndex
    {
        $path = (new ProjectLayout($this->rootPath))->mapIndex();
        if (!is_file($path)) {
            throw new RuntimeException('index missing (' . $this->relativePath($path) . ')');
        }

        try {
            return (new IndexReader())->read($path);
        } catch (Throwable $exception) {
            throw new RuntimeException('index invalid (' . $this->relativePath($path) . ')', 0, $exception);
        }
    }

    /** @param array<string, mixed> $result */
    private function leadLine(int $rank, array $result): string
    {
        $symbolId = is_string($result['symbol_id'] ?? null) ? $result['symbol_id'] : 'unknown';
        $symbol = preg_replace('/^[^:]+:/', '', $symbolId) ?: $symbolId;
        $path = is_string($result['file_path'] ?? null) ? $result['file_path'] : 'unknown';
        $start = is_int($result['start_line'] ?? null) ? $result['start_line'] : 0;
        $end = is_int($result['end_line'] ?? null) ? $result['end_line'] : $start;
        $range = $start > 0 ? ':' . $start . ($end > $start ? '-' . $end : '') : '';

        return sprintf('  rank %d %s — %s%s', $rank, $symbol, $path, $range);
    }

    private function looksLikeTestPath(string $path): bool
    {
        $normalized = strtolower(str_replace('\\', '/', $path));

        return str_starts_with($normalized, 'tests/')
            || str_starts_with($normalized, 'test/')
            || str_contains($normalized, '/tests/')
            || preg_match('/(?:^|\/)[^\/]*test\.php$/', $normalized) === 1;
    }

    private function relativePath(string $path): string
    {
        $root = rtrim(str_replace('\\', '/', $this->rootPath), '/');
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $root . '/')
            ? substr($normalized, strlen($root) + 1)
            : $normalized;
    }
}
