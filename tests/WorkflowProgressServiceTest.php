<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\WorkflowProgressService;
use voku\AgentLoop\Workflow\WorkflowProgressStep;

final class WorkflowProgressServiceTest extends TestCase
{
    public function testProjectsCurrentTaskProgressWithoutWritingOwnerState(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-progress-' . bin2hex(random_bytes(4));
        mkdir($root, 0o775, true);

        try {
            $before = scandir($root);
            $progress = (new WorkflowProgressService($root))->forTask('TASK-1');

            self::assertSame('TASK-1', $progress->taskId);
            self::assertSame('contract', $progress->currentStepId);
            self::assertSame(WorkflowProgressStep::STATE_CURRENT, $progress->steps[0]->state);
            self::assertStringContainsString('workflow plan TASK-1', $progress->nextAction);
            self::assertSame($before, scandir($root), 'progress projection is read-only');
        } finally {
            rmdir($root);
        }
    }
}
