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

            $approved = $contracts->approve('SCOPE-1', 'fixture', $contracts->load('SCOPE-1')->revision);
            self::assertSame(['tests'], $approved->scope);

            $snapshot = ImplementationSnapshot::capture($root, $approved);
            self::assertSame(['tests/FooTest.php'], array_column($snapshot->files, 'path'));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testExplicitScopeCannotSilentlyDropFileArguments(): void
    {
        $root = $this->root('exclude-file');
        /** @var list<array{files: list<string>, scope: string, expected: string}> $cases */
        $cases = [
            [
                'files' => ['src/Foo.php', 'tests/FooTest.php'],
                'scope' => 'tests/',
                'expected' => 'src/Foo.php',
            ],
            [
                'files' => ['src/Foo.php'],
                'scope' => 'src/Foo.php.bak',
                'expected' => 'src/Foo.php',
            ],
            [
                'files' => ['src/../Secret.php'],
                'scope' => 'src/',
                'expected' => 'Workflow scope escapes or ambiguously addresses',
            ],
        ];

        try {
            foreach ($cases as $index => $case) {
                $taskId = 'SCOPE-' . ($index + 10);
                $arguments = [$taskId, '--by', 'fixture'];
                foreach ($case['files'] as $file) {
                    $arguments = [...$arguments, '--file', $file];
                }
                $arguments = [
                    ...$arguments,
                    '--goal', 'Never drop a declared file.',
                    '--scope', $case['scope'],
                    '--validation', 'vendor/bin/phpunit',
                    '--format=json',
                ];

                ob_start();
                try {
                    $exit = (new WorkflowPlanCommand($root))->run($arguments);
                    $response = (string) ob_get_contents();
                } finally {
                    ob_end_clean();
                }

                self::assertSame(1, $exit, $case['scope']);
                $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($result);
                self::assertSame('error', $result['status']);
                self::assertStringContainsString($case['expected'], $result['error']);
                self::assertNull((new TaskContractStore($root))->find($taskId));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testRejectedScopeNarrowingDoesNotReviseApprovedContract(): void
    {
        $root = $this->root('reject-revision');
        $contracts = new TaskContractStore($root);
        $contracts->create(
            'SCOPE-REV',
            'Original approved scope.',
            ['src/Foo.php', 'tests/FooTest.php'],
            [],
            ['vendor/bin/phpunit'],
            'fixture',
        );
        $contracts->approve('SCOPE-REV', 'fixture', 1);

        try {
            ob_start();
            try {
                $exit = (new WorkflowPlanCommand($root))->run([
                    'SCOPE-REV', '--by', 'fixture', '--supersede',
                    '--file', 'src/Foo.php', '--file', 'tests/FooTest.php',
                    '--scope', 'src/',
                    '--goal', 'Narrow without losing a file.',
                    '--validation', 'vendor/bin/phpunit',
                    '--format=json',
                ]);
                $response = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }

            self::assertSame(1, $exit);
            $result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($result);
            self::assertStringContainsString('tests/FooTest.php', $result['error']);

            $unchanged = $contracts->load('SCOPE-REV');
            self::assertSame(1, $unchanged->revision);
            self::assertSame(['src/Foo.php', 'tests/FooTest.php'], $unchanged->scope);
            self::assertSame('fixture', $unchanged->approvedBy);
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
