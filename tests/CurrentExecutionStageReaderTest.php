<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Execution\CurrentExecutionStageReader;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionProfile;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\ExecutionStateStore;

final class CurrentExecutionStageReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-current-stage-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testRoleAndKindComeFromTheExactPersistedPlanStage(): void
    {
        $plan = ExecutionPlan::resolve(
            ExecutionProfile::firstParty(ExecutionProfileName::SURGICAL),
            'TASK-466',
            'run:TASK-466:1',
            1,
            [
                'path' => '.agent-loop/contracts/TASK-466.json',
                'sha256' => 'sha256:' . str_repeat('a', 64),
            ],
            str_repeat('1', 40),
            '2026-09-13T05:00:00+00:00',
        );
        $this->writePlan($plan);

        $this->writeState($plan, 'investigate', 1);
        $investigate = (new CurrentExecutionStageReader($this->root))->read('TASK-466');
        self::assertSame('investigate', $investigate->stageId);
        self::assertSame('investigator', $investigate->roleId);
        self::assertSame(ExecutionStageKind::AGENT, $investigate->stageKind);

        $this->writeState($plan, 'build', 1);
        $build = (new CurrentExecutionStageReader($this->root))->read('TASK-466');
        self::assertSame('build', $build->stageId);
        self::assertSame('builder', $build->roleId);
        self::assertSame(ExecutionStageKind::AGENT, $build->stageKind);

        $this->writeState($plan, 'verify', 1);
        $verify = (new CurrentExecutionStageReader($this->root))->read('TASK-466');
        self::assertSame('verify', $verify->stageId);
        self::assertNull($verify->roleId);
        self::assertSame(ExecutionStageKind::DETERMINISTIC, $verify->stageKind);
    }

    public function testCompletedExecutionHasNoCurrentStageRoleOrKind(): void
    {
        $plan = ExecutionPlan::resolve(
            ExecutionProfile::firstParty(ExecutionProfileName::SURGICAL),
            'TASK-466',
            'run:TASK-466:1',
            1,
            [
                'path' => '.agent-loop/contracts/TASK-466.json',
                'sha256' => 'sha256:' . str_repeat('b', 64),
            ],
            str_repeat('2', 40),
            '2026-09-13T05:00:00+00:00',
        );
        $this->writePlan($plan);
        $this->writeState($plan, null, 0);

        $completed = (new CurrentExecutionStageReader($this->root))->read('TASK-466');

        self::assertNull($completed->stageId);
        self::assertNull($completed->roleId);
        self::assertNull($completed->stageKind);
        self::assertSame(0, $completed->attempt);
    }

    private function writePlan(ExecutionPlan $plan): void
    {
        $path = (new ExecutionPlanStore($this->root))->path($plan->taskId);
        $this->writeJson($path, $plan->toArray());
    }

    private function writeState(ExecutionPlan $plan, ?string $stageId, int $attempt): void
    {
        $state = new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            $stageId,
            $attempt,
            $plan->baseCommit ?? 'candidate:initial',
            null,
            [],
        );
        $path = (new ExecutionStateStore($this->root))->path($plan->taskId);
        $this->writeJson($path, $state->toArray());
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($path, json_encode($value, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
