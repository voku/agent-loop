<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\HostFrontDoorCommand;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPlanCommand;

/**
 * Scope the implementation snapshot refuses at `finish` must be refused when
 * the Contract is planned, by the same rule.
 */
final class WorkflowPlanUnclosableScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-plan-unclosable-' . bin2hex(random_bytes(6));
        foreach ([
            'src',
            '.git',
            'vendor/voku/pkg',
            '.agent-loop/todo/cards',
            '.agent-loop/runs/OLD-1',
            '.agent-loop/learning/history',
            '.agent-loop/learning/constraints',
        ] as $directory) {
            mkdir($this->root . '/' . $directory, 0o775, true);
        }
        foreach ([
            'src/A.php' => "<?php\nreturn 'A';\n",
            '.git/config' => "[core]\n",
            'vendor/voku/pkg/Pkg.php' => "<?php\n",
            '.agent-loop/todo/cards/NG-1.md' => "# NG-1\n",
            '.agent-loop/runs/OLD-1/manifest.json' => "{}\n",
            '.agent-loop/learning/history/run.json' => "{}\n",
            '.agent-loop/learning/constraints/constraint.json' => "{}\n",
            '.agent-loop/init.json' => "{\"version\":1}\n",
            '.agent-loop/githooks.json' => "{}\n",
        ] as $file => $content) {
            file_put_contents($this->root . '/' . $file, $content);
        }
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function metadataScope(): iterable
    {
        yield 'board card (generated workflow state file)' => ['.agent-loop/todo/cards/NG-1.md'];
        yield 'run state file' => ['.agent-loop/runs/OLD-1/manifest.json'];
        yield 'workflow state file that does not exist yet' => ['.agent-loop/runs/NEW-1/manifest.json'];
        yield 'workflow root directory' => ['.agent-loop'];
        yield 'directory inside the workflow root' => ['.agent-loop/todo'];
        yield 'learning history file' => ['.agent-loop/learning/history/run.json'];
        yield 'learning history directory' => ['.agent-loop/learning/history'];
        yield 'git directory' => ['.git'];
        yield 'file inside the git directory' => ['.git/config'];
        yield 'root vendor directory' => ['vendor'];
    }

    #[DataProvider('metadataScope')]
    public function testPlanRefusesScopeTheImplementationSnapshotRefusesAtFinish(string $path): void
    {
        $result = $this->plan(['UNCLOSABLE-1', '--by', 'fixture', '--file', $path, '--goal', 'Edit it.', '--validation', 'true', '--format', 'json']);

        self::assertSame(1, $result['exit']);
        $payload = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('workflow plan', $payload['command']);
        self::assertSame('error', $payload['status']);
        self::assertStringContainsString('workflow/dependency metadata, not implementation content: ' . $path . '.', $payload['error']);
        self::assertStringContainsString('could never close', $payload['error']);
        self::assertNull((new TaskContractStore($this->root))->find('UNCLOSABLE-1'));

        // The finish-time guard is unchanged and refuses the very same path.
        try {
            ImplementationSnapshot::capture($this->root, $this->unplannedContract([$path]));
            self::fail('The implementation snapshot accepted ' . $path);
        } catch (RuntimeException $exception) {
            self::assertSame(
                'Implementation snapshot scope is workflow/dependency metadata, not implementation content: ' . $path,
                $exception->getMessage(),
            );
        }
    }

    /** @return iterable<string, array{string}> */
    public static function implementationScope(): iterable
    {
        yield 'source file' => ['src/A.php'];
        yield 'source directory' => ['src'];
        yield 'project config under the workflow root' => ['.agent-loop/init.json'];
        yield 'git hook config under the workflow root' => ['.agent-loop/githooks.json'];
        yield 'durable learning file' => ['.agent-loop/learning/constraints/constraint.json'];
        yield 'vendored package directory' => ['vendor/voku/pkg'];
    }

    #[DataProvider('implementationScope')]
    public function testPlanStillAcceptsScopeTheImplementationSnapshotAccepts(string $path): void
    {
        $result = $this->plan(['CLOSABLE-1', '--by', 'fixture', '--file', $path, '--goal', 'Edit it.', '--validation', 'true', '--format', 'json']);

        self::assertSame(0, $result['exit'], $result['stdout']);
        $contracts = new TaskContractStore($this->root);
        self::assertSame([$path], $contracts->load('CLOSABLE-1')->scope);
        $snapshot = ImplementationSnapshot::capture($this->root, $contracts->approve('CLOSABLE-1', 'fixture', 1));
        self::assertNotSame([], $snapshot->files);
    }

    /** @return iterable<string, array{string}> */
    public static function directoryScopeATaskMayDelete(): iterable
    {
        yield 'directory of the Learning root' => ['.agent-loop/learning/constraints'];
        yield 'Learning root itself' => ['.agent-loop/learning'];
    }

    /**
     * The snapshot refuses these only while they are directories; once the task
     * deleted them it records the deletion, so planning must not refuse them.
     */
    #[DataProvider('directoryScopeATaskMayDelete')]
    public function testPlanDefersDirectoryScopeTheSnapshotRefusesOnlyWhileItExists(string $path): void
    {
        self::assertTrue(is_dir($this->root . '/' . $path));

        $result = $this->plan(['DEFERRED-1', '--by', 'fixture', '--file', $path, '--goal', 'Delete it.', '--validation', 'true', '--format', 'json']);

        self::assertSame(0, $result['exit'], $result['stdout']);
        self::assertSame([$path], (new TaskContractStore($this->root))->load('DEFERRED-1')->scope);
    }

    public function testSupersedeAndAddScopeRefuseMetadataScopeAndKeepTheCurrentRevision(): void
    {
        self::assertSame(0, $this->plan(['REVISE-1', '--by', 'fixture', '--file', 'src/A.php', '--goal', 'Edit it.', '--validation', 'true'])['exit']);
        $contracts = new TaskContractStore($this->root);
        $contracts->approve('REVISE-1', 'fixture', 1);

        foreach ([
            ['--file', '.agent-loop/todo/cards/NG-1.md', '--goal', 'Edit the card.', '--validation', 'true', '--supersede'],
            ['--add-scope', '.agent-loop/todo/cards/NG-1.md', '--supersede'],
        ] as $revision) {
            $result = $this->plan(['REVISE-1', '--by', 'fixture', ...$revision, '--format', 'json']);

            self::assertSame(1, $result['exit'], $result['stdout']);
            self::assertStringContainsString(
                'not implementation content: .agent-loop/todo/cards/NG-1.md.',
                json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR)['error'],
            );
            $current = $contracts->load('REVISE-1');
            self::assertSame(1, $current->revision);
            self::assertSame(TaskContract::APPROVED, $current->status);
            self::assertSame([], $contracts->supersededRevisions('REVISE-1'));
        }
    }

    public function testQuickRefusesMetadataScopeBeforeItCreatesAContract(): void
    {
        ob_start();
        try {
            $exit = (new HostFrontDoorCommand($this->root))->run('quick', [
                'QUICK-CARD',
                'Edit the card',
                '--file=.agent-loop/todo/cards/NG-1.md',
                '--format=json',
            ]);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(1, $exit);
        $payload = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('refused', $payload['mutation_status']);
        self::assertStringContainsString('not implementation content: .agent-loop/todo/cards/NG-1.md.', $payload['error']['message']);
        self::assertNull((new TaskContractStore($this->root))->find('QUICK-CARD'));
    }

    /**
     * @param list<string> $args
     * @return array{exit: int, stdout: string}
     */
    private function plan(array $args): array
    {
        ob_start();
        try {
            $exit = (new WorkflowPlanCommand($this->root))->run($args);
            $stdout = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return ['exit' => $exit, 'stdout' => $stdout];
    }

    /**
     * An approved Contract built without the planning front door, as a Contract
     * written before plan-time refusal existed would be.
     *
     * @param list<string> $scope
     */
    private function unplannedContract(array $scope): TaskContract
    {
        return new TaskContract(
            'UNPLANNED-1',
            'Snapshot fixture.',
            $scope,
            [],
            ['true'],
            TaskContract::APPROVED,
            1,
            '2026-01-01T00:00:00+00:00',
            '2026-01-01T00:00:00+00:00',
            $this->root . '/.agent-loop/contracts/UNPLANNED-1/contract.json',
            'fixture',
        );
    }
}
