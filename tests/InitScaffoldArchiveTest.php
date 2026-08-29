<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

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
        self::assertDirectoryExists($this->root . '/.agent-loop/todo/cards');
        self::assertDirectoryExists($this->root . '/.agent-loop/todo/archive');
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
            "/map/\n/recall/\n/sessions/\n/edit/\n/runs/*/*.lock\n/tool-inventory.json\n",
            file_get_contents($this->root . '/.agent-loop/.gitignore'),
        );
        self::assertStringContainsString('[CREATE] .agent-loop/.gitignore', $first['output']);

        $second = $this->dispatch(['agent-loop', 'init', 'scaffold', '--prefix=SHD']);

        self::assertSame(0, $second['exit'], $second['output']);
        self::assertStringContainsString('[SKIP] .agent-loop/.gitignore already exists', $second['output']);
        self::assertSame($rootIgnore, file_get_contents($this->root . '/.gitignore'));
        self::assertSame(
            "/map/\n/recall/\n/sessions/\n/edit/\n/runs/*/*.lock\n/tool-inventory.json\n",
            file_get_contents($this->root . '/.agent-loop/.gitignore'),
        );
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
        self::assertStringContainsString(
            '- **Project prefix:** SHD',
            (string) file_get_contents($this->root . '/.agent-loop/todo/board.md'),
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
        self::assertStringContainsString('[SKIP] .agent-loop/todo/kanban.config.json already exists', $second['output']);
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
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/todo/archive/', $dryRun['output']);
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/todo/kanban.config.json', $dryRun['output']);
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/tasks/DEMO-1.md', $dryRun['output']);
        self::assertStringContainsString('[DRY-RUN] would create .agent-loop/todo/cards/DEMO-1.md', $dryRun['output']);
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
