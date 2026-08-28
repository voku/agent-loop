<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

final class WorkflowPlanScopeNormalizationTest extends TestCase
{
    public function testPlanNormalizesTrailingSlashBeforeApprovalAndSnapshotReadiness(): void
    {
        $root = $this->root('normalize');
        mkdir($root . '/tests', 0o775, true);
        file_put_contents($root . '/tests/FooTest.php', "<?php\n");

        try {
            ob_start();
            try {
                $exit = (new WorkflowPlanCommand($root))->run([
                    'SCOPE-1',
                    '--by', 'fixture',
                    '--file', 'tests/FooTest.php',
                    '--goal', 'Keep directory scope canonical.',
                    '--scope', 'tests/',
                    '--validation', 'vendor/bin/phpunit tests/FooTest.php',
                ]);
            } finally {
                ob_end_clean();
            }

            self::assertSame(0, $exit);
            $contracts = new TaskContractStore($root);
            $candidate = $contracts->load('SCOPE-1');
            self::assertSame(['tests'], $candidate->scope);

            $approved = $contracts->approve('SCOPE-1', 'fixture');
            self::assertSame(['tests'], $approved->scope);

            $snapshot = ImplementationSnapshot::capture($root, $approved);
            self::assertSame(['tests/FooTest.php'], array_column($snapshot->files, 'path'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPlanStillRejectsEscapingAndAmbiguousScopesBeforeContractExists(): void
    {
        $root = $this->root('invalid');

        try {
            foreach (['../tests', 'tests//FooTest.php', 'C:tests'] as $index => $scope) {
                $taskId = 'SCOPE-' . ($index + 2);

                ob_start();
                try {
                    $exit = (new WorkflowPlanCommand($root))->run([
                        $taskId,
                        '--by', 'fixture',
                        '--file', 'tests/FooTest.php',
                        '--goal', 'Reject unsafe scope syntax.',
                        '--scope', $scope,
                        '--validation', 'vendor/bin/phpunit',
                    ]);
                } finally {
                    ob_end_clean();
                }

                self::assertSame(1, $exit, $scope);
                self::assertNull((new TaskContractStore($root))->find($taskId), $scope);
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function root(string $suffix): string
    {
        return sys_get_temp_dir() . '/agent-loop-scope-' . $suffix . '-' . bin2hex(random_bytes(6));
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
