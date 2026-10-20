<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Support;

use RuntimeException;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionProfileSelectionStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

final class ManualProfileLifecycleFixture
{
    public static function beforeEnter(string $root, string $taskId): void
    {
        if (is_file($root . '/.git') || is_dir($root . '/.git')) {
            return;
        }

        $contract = (new TaskContractStore($root))->find($taskId);
        if (!$contract instanceof TaskContract || $contract->status !== TaskContract::APPROVED) {
            return;
        }

        try {
            $plan = (new ExecutionPlanStore($root))->find($taskId);
        } catch (RuntimeException) {
            return;
        }
        if ($plan !== null && $plan->contractRevision === $contract->revision) {
            return;
        }

        $selections = new ExecutionProfileSelectionStore($root);
        try {
            $selection = $selections->find($taskId);
        } catch (RuntimeException) {
            return;
        }
        if ($selection !== null && $selection->contractRevision === $contract->revision) {
            return;
        }

        $selections->select($taskId, ExecutionProfileName::MANUAL, 'phpunit-fixture');
    }
}
