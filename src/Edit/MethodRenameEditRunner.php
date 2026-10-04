<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit;

use RuntimeException;
use voku\AgentEdit\EditEngine;
use voku\AgentMap\Index\AgentMapIndex;
use voku\AgentMap\Index\IndexReader;
use voku\AgentMap\Rename\MethodRenamePlanner;

/** Plans one current method rename, then delegates all mutation to agent-edit. */
final readonly class MethodRenameEditRunner implements EditRunner
{
    public function __construct(
        private MethodRenamePlanner $planner = new MethodRenamePlanner(),
        private IndexReader $reader = new IndexReader(),
        private EditEngine $engine = new EditEngine(),
    ) {
    }

    /** Replans at the mutation boundary and applies only the resulting current safe contract. */
    public function run(EditExecution $execution): EditRunResult
    {
        $replacement = $execution->request->replacementMethod;
        if ($replacement === null) {
            throw new RuntimeException('Method rename runner has no replacement method.');
        }

        $map = $this->reader->read($execution->request->mapIndex);
        $runtimeRoot = rtrim(str_replace('\\', '/', $execution->request->mapRoot), '/');
        if (rtrim(str_replace('\\', '/', $map->root), '/') !== $runtimeRoot) {
            $map = new AgentMapIndex(
                $map->schemaVersion,
                $runtimeRoot,
                $map->backend,
                $map->files,
                $map->relations,
                $map->diagnostics,
                $map->fingerprint,
            );
        }
        if ($map->staleEntries() !== []) {
            throw new RuntimeException('Method rename evidence is stale; rebuild the map and re-plan before applying.');
        }

        $plan = $this->planner->plan($map, $execution->request->target, $replacement)->toArray();

        $result = $this->engine->apply($plan, $map, $execution->request->mapRoot);

        return new EditRunResult($result->status, $result->exitCode, $result->stdout, $result->stderr);
    }
}
