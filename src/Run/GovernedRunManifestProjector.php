<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use voku\AgentLoop\Workflow\ExecutionContractStore;

/**
 * Adds the workflow-owned L2 execution-contract gate to the existing
 * cross-package run projection without duplicating the owning package readers.
 */
final readonly class GovernedRunManifestProjector
{
    public function __construct(private string $rootPath)
    {
    }

    public function project(string $taskId): RunManifest
    {
        $base = (new RunManifestProjector($this->rootPath))->project($taskId);
        $contract = (new ExecutionContractStore($this->rootPath))->inspect($taskId);
        $references = $base->references;
        $references['execution_contract'] = $contract;

        $contractState = is_string($contract['state'] ?? null) ? $contract['state'] : 'invalid';
        $state = $base->state;
        $nextAction = $base->nextAction;

        if (in_array($contractState, ['blocked', 'rejected'], true)) {
            $state = 'blocked';
            $nextAction = 'agent-loop workflow status ' . $taskId . ' --format=json';
        } elseif (in_array($contractState, ['missing', 'stale', 'invalid'], true)) {
            if ($state !== 'blocked') {
                $state = 'incomplete';
                $nextAction = 'agent-loop workflow contract ' . $taskId . ' --status ready --from <l1.md> --by <actor>';
            }
        } elseif ($contractState === 'pending_recall' && $state !== 'blocked') {
            $state = 'incomplete';
        }

        return new RunManifest(
            $base->taskId,
            $base->runId,
            $base->mode,
            $state,
            $references,
            $base->disagreements,
            $nextAction,
        );
    }
}
