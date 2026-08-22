<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use Closure;
use RuntimeException;
use voku\AgentLoop\Edit\Refactor\RenamePlanApplier;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** Plans one current method rename, then delegates all mutation to the shared rename-plan boundary. */
final readonly class MethodRenameEditRunner implements EditRunner
{
    private RenamePlanApplier $applier;

    /**
     * @param (Closure(string, string): bool)|null $renameOperation
     * @param (Closure(string): array{exit_code: int, stdout: string, stderr: string})|null $lintOperation
     */
    public function __construct(
        private MethodRenamePlanner $planner = new MethodRenamePlanner(),
        private IndexReader $reader = new IndexReader(),
        ?Closure $renameOperation = null,
        ?Closure $lintOperation = null,
        ?RenamePlanApplier $applier = null,
    ) {
        $this->applier = $applier ?? new RenamePlanApplier($renameOperation, $lintOperation);
    }

    /** Replans at the mutation boundary and applies only the resulting current safe contract. */
    public function run(EditExecution $execution): EditRunResult
    {
        $replacement = $execution->request->replacementMethod;
        if ($replacement === null) {
            throw new RuntimeException('Method rename runner has no replacement method.');
        }

        $map = $this->reader->read($execution->request->mapIndex);
        $map = $this->withRuntimeRoot($map, $execution->request->mapRoot);
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Method rename evidence is stale; rebuild the map and re-plan before applying.');
        }

        $plan = $this->planner->plan($map, $execution->request->target, $replacement)->toArray();

        return $this->applier->apply($plan, $map, $execution->request->mapRoot);
    }

    /**
     * Compatibility seam for focused method-runner tests and callers that preflight a public plan.
     *
     * @param array<string, mixed> $plan
     * @return array<string, string> absolute source path => final contents
     */
    public function preflight(array $plan, AgentMapIndex $map, string $root): array
    {
        return $this->applier->preflight($plan, $map, $root)['files'];
    }

    /** Rebinds a persisted map to the runtime project root without changing map identity. */
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
}
