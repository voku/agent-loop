<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Dispatcher;

final class InitScaffoldVcsPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-scaffold-vcs-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->git(['init', '--initial-branch=main']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testGeneratedPolicyIgnoresOnlyDerivedWorkflowState(): void
    {
        file_put_contents($this->root . '/.gitignore', "/vendor/\n");

        ob_start();
        $exit = (new Dispatcher($this->root))->run([
            'agent-loop',
            'init',
            'scaffold',
            '--prefix=VCS',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertSame("/vendor/\n", file_get_contents($this->root . '/.gitignore'));
        self::assertFileExists($this->root . '/.agent-loop/.gitignore');

        $derived = [
            '.agent-loop/map/php-symbols.json',
            '.agent-loop/recall/VCS-1/system.md',
            '.agent-loop/sessions/run-1/session.json',
            '.agent-loop/edit/VCS-1/execution.json',
            '.agent-loop/tool-inventory.json',
        ];
        foreach ($derived as $path) {
            $this->writeFixture($path);
            self::assertSame(0, $this->gitExit(['check-ignore', '--quiet', $path]), $path . ' must be ignored');
        }

        $durable = [
            '.agent-loop/init.json',
            '.agent-loop/todo/kanban.config.json',
            '.agent-loop/todo/board.md',
            '.agent-loop/tasks/VCS-1.md',
            '.agent-loop/contracts/VCS-1/contract.json',
            '.agent-loop/runs/VCS-1/run.json',
            '.agent-loop/learning/findings/finding.json',
        ];
        foreach ($durable as $path) {
            if (!is_file($this->root . '/' . $path)) {
                $this->writeFixture($path);
            }
            self::assertSame(1, $this->gitExit(['check-ignore', '--quiet', $path]), $path . ' must remain trackable');
        }
    }

    private function writeFixture(string $relativePath): void
    {
        $path = $this->root . '/' . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }
        file_put_contents($path, "fixture\n");
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): void
    {
        $exit = $this->gitExit($arguments);
        if ($exit !== 0) {
            throw new RuntimeException('Git command failed: git ' . implode(' ', $arguments));
        }
    }

    /** @param list<string> $arguments */
    private function gitExit(array $arguments): int
    {
        $command = array_merge(['git', '-C', $this->root], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Git command.');
        }

        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
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
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
