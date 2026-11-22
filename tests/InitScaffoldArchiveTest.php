<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\TaskContractStore;

final class InitScaffoldArchiveTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-scaffold-archive-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultScaffoldCreatesInfrastructureWithoutInventingBoardIdentityOrDemoWork(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'scaffold']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/todo/cards');
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop/todo/archive');
        self::assertDirectoryExists($this->root . '/.agent-loop/tasks');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/todo/kanban.config.json');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/todo/board.md');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/todo/cards/DEMO-1.md');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/tasks/DEMO-1.md');
        self::assertStringContainsString('init scaffold --prefix=<PROJECT>', $result['output']);
    }

    public function testScaffoldCreatesScopedVcsPolicyWithoutTouchingRootIgnore(): void
    {
        $rootIgnore = "/vendor/\n/project-cache/\n";
        file_put_contents($this->root . '/.gitignore', $rootIgnore);

        $first = $this->dispatch(['agent-loop', 'init', 'scaffold', '--prefix=SHD']);

        self::assertSame(0, $first['exit'], $first['output']);
        self::assertSame($rootIgnore, file_get_contents($this->root . '/.gitignore'));
        self::assertSame(
            "/map/\n/recall/\n/sessions/\n/edit/\n*.lock\n/tool-inventory.json\n",
            file_get_contents($this->root . '/.agent-loop/.gitignore'),
        );
        self::assertStringContainsString('[CREATE] .agent-loop/.gitignore', $first['output']);

        $second = $this->dispatch(['agent-loop', 'init', 'scaffold', '--prefix=SHD']);

        self::assertSame(0, $second['exit'], $second['output']);
        self::assertStringContainsString('[SKIP] .agent-loop/.gitignore already exists', $second['output']);
        self::assertSame($rootIgnore, file_get_contents($this->root . '/.gitignore'));
        self::assertSame(
            "/map/\n/recall/\n/sessions/\n/edit/\n*.lock\n/tool-inventory.json\n",
            file_get_contents($this->root . '/.agent-loop/.gitignore'),
        );
    }

    public function testScaffoldedVcsPolicyIgnoresEveryLockFileTheWorkflowRootCanHold(): void
    {
        self::assertSame(0, $this->dispatch(['agent-loop', 'init', 'scaffold', '--prefix=SHD'])['exit']);
        exec('git -C ' . escapeshellarg($this->root) . ' init -q 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        // A real Contract mutation leaves its lock file behind; the other paths are named by their creator.
        (new TaskContractStore($this->root))->create('LOCK-1', 'Lock fixture.', ['src'], [], ['true'], 'fixture');
        $contractLocks = glob($this->root . '/.agent-loop/contracts/.contract-mutation-*.lock');
        self::assertIsArray($contractLocks);
        self::assertCount(1, $contractLocks);

        $layout = new ProjectLayout($this->root);
        $prefix = strlen($this->root) + 1;
        $locks = [
            // TaskContractStore::withMutationLock()
            substr($contractLocks[0], $prefix),
            // ExecutionStateStore::acquireLock() via ProjectLayout::executionStateLockPath()
            substr($layout->executionStateLockPath('LOCK-1'), $prefix),
            // GovernedRunStore::archive() moves the whole Run directory, lock included, below runs/.history/<task>/<run>/
            substr($layout->runHistoryRoot('LOCK-1'), $prefix) . '/run-1/execution-state.lock',
            // InitLanguageConfigWriter
            '.agent-loop/.init-language.lock',
        ];
        self::assertSame('.agent-loop/runs/LOCK-1/execution-state.lock', $locks[1]);
        self::assertSame('.agent-loop/runs/.history/LOCK-1/run-1/execution-state.lock', $locks[2]);

        foreach ($locks as $lock) {
            exec('git -C ' . escapeshellarg($this->root) . ' check-ignore -q -- ' . escapeshellarg($lock), $ignored, $exit);
            self::assertSame(0, $exit, $lock . ' is not ignored by the scaffolded .agent-loop/.gitignore');
        }
        // The pattern is not a blanket ignore: durable state stays visible to Git.
        exec('git -C ' . escapeshellarg($this->root) . ' check-ignore -q -- .agent-loop/contracts/LOCK-1/contract.json', $ignored, $exit);
        self::assertSame(1, $exit);
    }

    public function testExplicitPrefixCreatesEmptyLifecycleCompleteBoard(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'scaffold', '--prefix=SHD']);

        self::assertSame(0, $result['exit'], $result['output']);
        $configPath = $this->root . '/.agent-loop/todo/kanban.config.json';
        self::assertFileExists($configPath);
        $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('SHD', $config['projectPrefix'] ?? null);
        self::assertSame('todo/archive', $config['archiveDirectory'] ?? null);
        self::assertFileDoesNotExist(
            $this->root . '/.agent-loop/todo/board.md',
            'Loop must not duplicate agent-kanban metadata beside owner configuration.',
        );
        self::assertFileDoesNotExist($this->root . '/.agent-loop/todo/cards/DEMO-1.md');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/tasks/DEMO-1.md');

        $verify = $this->dispatch(['agent-loop', 'board', 'verify']);
        self::assertSame(0, $verify['exit'], $verify['output']);
    }

    public function testExplicitDemoCanArchiveItsCardAndRerunWithoutRewritingConfig(): void
    {
        $first = $this->dispatch(['agent-loop', 'init', 'scaffold', '--demo']);

        self::assertSame(0, $first['exit'], $first['output']);
        self::assertDirectoryExists($this->root . '/.agent-loop/todo/archive');
        $configPath = $this->root . '/.agent-loop/todo/kanban.config.json';
        self::assertFileExists($configPath);
        $config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('DEMO', $config['projectPrefix'] ?? null);
        self::assertSame('todo/archive', $config['archiveDirectory'] ?? null);
        $originalConfig = (string) file_get_contents($configPath);

        $second = $this->dispatch(['agent-loop', 'init', 'scaffold', '--demo']);
        self::assertSame(0, $second['exit'], $second['output']);
        self::assertStringContainsString('[OK] board configuration/storage ready for DEMO', $second['output']);
        self::assertSame($originalConfig, file_get_contents($configPath));

        $archive = $this->dispatch(['agent-loop', 'board', 'card', 'archive', 'DEMO-1']);
        self::assertSame(0, $archive['exit'], $archive['output']);
        self::assertFileDoesNotExist($this->root . '/.agent-loop/todo/cards/DEMO-1.md');
        self::assertFileExists($this->root . '/.agent-loop/todo/archive/DEMO-1.md');
    }

    public function testDemoDryRunNamesArchiveConfigAndDemoArtifactsWithoutWriting(): void
    {
        $dryRun = $this->dispatch(['agent-loop', 'init', 'scaffold', '--demo', '--dry-run']);

        self::assertSame(0, $dryRun['exit'], $dryRun['output']);
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/.gitignore', $dryRun['output']);
        self::assertStringContainsString('[DRY-RUN] would bootstrap board configuration/storage for DEMO', $dryRun['output']);
        self::assertStringNotContainsString('kanban.config.json', $dryRun['output']);
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/tasks/DEMO-1.md', $dryRun['output']);
        self::assertStringContainsString('[DRY-RUN] would create demo board card DEMO-1', $dryRun['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    /**
     * @param list<string> $argv
     * @return array{exit: int, output: string}
     */
    private function dispatch(array $argv): array
    {
        ob_start();
        $exit = (new Dispatcher($this->root))->run($argv);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
