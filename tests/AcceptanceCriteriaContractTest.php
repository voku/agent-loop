<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class AcceptanceCriteriaContractTest extends TestCase
{
    public function testPlanPersistsAndApprovesExplicitAcceptanceCriteria(): void
    {
        $root = $this->root('plan');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ACCEPT-1',
                '--by', 'agent',
                '--file', 'src/Foo.php',
                '--goal', 'Preserve the task completion intent.',
                '--validation', 'composer ci',
                '--acceptance', 'Recall exposes the approved outcome.',
                '--acceptance', 'Workflow report keeps the outcome visible.',
                '--acceptance', 'Recall exposes the approved outcome.',
            ]);
            ob_end_clean();

            self::assertSame(0, $exit);
            $store = new TaskContractStore($root);
            $candidate = $store->load('ACCEPT-1');
            self::assertSame(TaskContract::CANDIDATE, $candidate->status);
            self::assertSame([
                'Recall exposes the approved outcome.',
                'Workflow report keeps the outcome visible.',
            ], $candidate->acceptanceCriteria);
            self::assertSame($candidate->acceptanceCriteria, $candidate->uncoveredAcceptanceCriteria());

            $approved = $store->approve('ACCEPT-1', 'lars');
            self::assertSame(TaskContract::APPROVED, $approved->status);
            self::assertSame($candidate->acceptanceCriteria, $approved->acceptanceCriteria);
            self::assertSame([], $approved->acceptanceObservations);

            $decoded = json_decode((string) file_get_contents($approved->path), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame($approved->acceptanceCriteria, $decoded['acceptance_criteria'] ?? null);
            self::assertSame([], $decoded['acceptance_observations'] ?? null);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanPersistsAcceptanceObservationMappings(): void
    {
        $root = $this->root('plan-observation');

        try {
            ob_start();
            $exit = (new WorkflowPlanCommand($root))->run([
                'ACCEPT-1',
                '--by', 'agent',
                '--file', 'src/Foo.php',
                '--goal', 'Bind acceptance to declared observation intent.',
                '--validation', 'vendor/bin/phpunit --filter FooTest',
                '--validation', 'composer ci',
                '--acceptance', 'Focused behavior works.',
                '--acceptance', 'Repository remains valid.',
                '--acceptance-observation', json_encode([
                    'acceptance' => 'Focused behavior works.',
                    'validations' => ['vendor/bin/phpunit --filter FooTest'],
                ], JSON_THROW_ON_ERROR),
            ]);
            ob_end_clean();

            self::assertSame(0, $exit);
            $contract = (new TaskContractStore($root))->load('ACCEPT-1');
            self::assertSame([[
                'acceptance' => 'Focused behavior works.',
                'validations' => ['vendor/bin/phpunit --filter FooTest'],
            ]], $contract->acceptanceObservations);
            self::assertSame(['Repository remains valid.'], $contract->uncoveredAcceptanceCriteria());
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testStorePersistsObservationCoverageAndReportsUncoveredCriteria(): void
    {
        $root = $this->root('observations');
        $store = new TaskContractStore($root);

        try {
            $contract = $store->create(
                'ACCEPT-1',
                'Map required outcomes to declared observations.',
                ['src/Foo.php'],
                [],
                ['vendor/bin/phpunit --filter FooTest', 'composer ci'],
                'agent',
                acceptanceCriteria: ['Focused behavior works.', 'Repository remains valid.'],
                acceptanceObservations: [[
                    'acceptance' => 'Focused behavior works.',
                    'validations' => ['vendor/bin/phpunit --filter FooTest'],
                ]],
            );

            self::assertSame([[
                'acceptance' => 'Focused behavior works.',
                'validations' => ['vendor/bin/phpunit --filter FooTest'],
            ]], $contract->acceptanceObservations);
            self::assertSame(['Repository remains valid.'], $contract->uncoveredAcceptanceCriteria());

            $approved = $store->approve('ACCEPT-1', 'lars');
            self::assertSame($contract->acceptanceObservations, $approved->acceptanceObservations);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDanglingObservationValidationFailsClosed(): void
    {
        $root = $this->root('dangling-validation');
        $store = new TaskContractStore($root);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unknown validation command');
            $store->create(
                'ACCEPT-1',
                'Reject observations outside Contract validation.',
                ['src/Foo.php'],
                [],
                ['composer ci'],
                'agent',
                acceptanceCriteria: ['Focused behavior works.'],
                acceptanceObservations: [[
                    'acceptance' => 'Focused behavior works.',
                    'validations' => ['vendor/bin/phpunit --filter FooTest'],
                ]],
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testDanglingObservationAcceptanceFailsClosed(): void
    {
        $root = $this->root('dangling-acceptance');
        $store = new TaskContractStore($root);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('unknown acceptance criterion');
            $store->create(
                'ACCEPT-1',
                'Reject observations outside Contract acceptance.',
                ['src/Foo.php'],
                [],
                ['composer ci'],
                'agent',
                acceptanceCriteria: ['Focused behavior works.'],
                acceptanceObservations: [[
                    'acceptance' => 'Different outcome.',
                    'validations' => ['composer ci'],
                ]],
            );
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRevisionArchivesPreviousAcceptanceCriteriaAndObservationCoverage(): void
    {
        $root = $this->root('revision');
        $store = new TaskContractStore($root);

        try {
            $store->create(
                'ACCEPT-1',
                'Initial intent.',
                ['src/Foo.php'],
                [],
                ['composer ci'],
                'agent',
                acceptanceCriteria: ['Initial required outcome.'],
                acceptanceObservations: [[
                    'acceptance' => 'Initial required outcome.',
                    'validations' => ['composer ci'],
                ]],
            );
            $store->approve('ACCEPT-1', 'lars');

            $revised = $store->revise(
                'ACCEPT-1',
                'Revised intent.',
                ['src/Foo.php'],
                [],
                ['composer ci'],
                'agent',
                acceptanceCriteria: ['Revised required outcome.'],
                acceptanceObservations: [[
                    'acceptance' => 'Revised required outcome.',
                    'validations' => ['composer ci'],
                ]],
            );

            self::assertSame(2, $revised->revision);
            self::assertSame(['Revised required outcome.'], $revised->acceptanceCriteria);
            self::assertSame([], $revised->uncoveredAcceptanceCriteria());

            $historyPath = dirname($revised->path) . '/history/contract.001.json';
            self::assertFileExists($historyPath);
            $history = json_decode((string) file_get_contents($historyPath), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame(['Initial required outcome.'], $history['acceptance_criteria'] ?? null);
            self::assertSame([[
                'acceptance' => 'Initial required outcome.',
                'validations' => ['composer ci'],
            ]], $history['acceptance_observations'] ?? null);
            self::assertSame(TaskContract::SUPERSEDED, $history['status'] ?? null);
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testLegacyContractWithoutAcceptanceCriteriaRemainsReadable(): void
    {
        $root = $this->root('legacy');
        $path = $root . '/.agent-loop/contracts/ACCEPT-1/contract.json';
        self::assertTrue(mkdir(dirname($path), 0o775, true));
        file_put_contents($path, json_encode([
            'schema_version' => '1.0',
            'kind' => 'task_contract',
            'task_id' => 'ACCEPT-1',
            'goal' => 'Legacy intent.',
            'scope' => ['src/Foo.php'],
            'non_goals' => [],
            'validation' => ['composer ci'],
            'tags' => [],
            'behavior_anchors' => [],
            'operating_prompt_manifest' => null,
            'operating_prompts' => [],
            'status' => TaskContract::CANDIDATE,
            'revision' => 1,
            'planned_by' => 'agent',
            'base_commit' => null,
            'approved_by' => null,
            'approved_at' => null,
            'created_at' => '2026-08-13T08:00:00+00:00',
            'updated_at' => '2026-08-13T08:00:00+00:00',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        try {
            $contract = (new TaskContractStore($root))->load('ACCEPT-1');
            self::assertSame([], $contract->acceptanceCriteria);
            self::assertSame([], $contract->acceptanceObservations);
            self::assertSame([], $contract->uncoveredAcceptanceCriteria());
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function root(string $suffix): string
    {
        return sys_get_temp_dir() . '/agent-loop-acceptance-' . $suffix . '-' . bin2hex(random_bytes(6));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path . '/' . $entry;
            is_dir($full) ? $this->removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }
}
