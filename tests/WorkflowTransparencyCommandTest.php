<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\After;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowTransparencyCommand;

final class WorkflowTransparencyCommandTest extends TestCase
{
    private const string TASK_ID = 'TRANSPARENCY-CMD-1';

    /** @var list<string> */
    private array $tempDirs = [];

    #[After]
    public function cleanupTempDirs(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
        $this->tempDirs = [];
    }

    public function testTextOutputEscapesHostileRepositoryPathBytes(): void
    {
        $this->requireGit();
        $root = $this->repository();
        file_put_contents($root . '/seed.txt', "seed\n");
        file_put_contents($root . '/.gitignore', ".agent-loop/\n");
        $this->git($root, ['add', 'seed.txt', '.gitignore']);
        $this->git($root, ['commit', '-m', 'base']);
        $base = trim($this->git($root, ['rev-parse', 'HEAD']));

        $contracts = new TaskContractStore($root);
        $contracts->create(
            self::TASK_ID,
            'Render repository observation safely.',
            ['.'],
            [],
            ['php -r "exit(0);"'],
            'fixture-planner',
            baseCommit: $base,
        );
        $contracts->approve(self::TASK_ID, 'fixture-approver');

        $hostilePath = "line\nbreak-\x1b[31m.txt";
        file_put_contents($root . '/' . $hostilePath, "content\n");

        ob_start();
        $exit = (new WorkflowTransparencyCommand($root))->run([self::TASK_ID]);
        $output = ob_get_clean();

        self::assertSame(0, $exit);
        self::assertIsString($output);
        self::assertStringContainsString(
            '[changed_in_scope/repository_observation] line\\nbreak-\\x1B[31m.txt',
            $output,
        );
        self::assertStringNotContainsString($hostilePath, $output);
    }

    private function requireGit(): void
    {
        if ($this->process(getcwd() ?: '.', ['git', '--version'])['exit'] !== 0) {
            self::markTestSkipped('Git is required for transparency command coverage.');
        }
    }

    private function repository(): string
    {
        $root = $this->tempDir();
        $this->git($root, ['init']);
        $this->git($root, ['config', 'user.email', 'transparency-command@example.test']);
        $this->git($root, ['config', 'user.name', 'Transparency Command Test']);
        $this->git($root, ['config', 'commit.gpgsign', 'false']);

        return $root;
    }

    /** @param list<string> $args */
    private function git(string $root, array $args): string
    {
        $result = $this->process($root, ['git', ...$args]);
        self::assertSame(0, $result['exit'], $result['stderr']);

        return $result['stdout'];
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private function process(string $root, array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'Unable to start process.'];
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    private function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/agent-loop-transparency-command-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($dir, 0o777, true));
        $this->tempDirs[] = $dir;

        return $dir;
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
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove transparency command fixture directory.');
        }
    }
}
