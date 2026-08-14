<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Edit\EditCommand;
use voku\AgentLoop\GitWorkTree;

/**
 * Replays a public package fix through the same edit path consumers use.
 *
 * The Linux baseline deliberately has no method boundary. It is only a result
 * comparison; the mechanical route must additionally retain map freshness,
 * method scoping, PHP linting, and its zero-model-use audit record.
 *
 * @internal
 */
final class AgentLoopHistoricalEditReplayTest extends TestCase
{
    private string $packageRoot;

    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function setUp(): void
    {
        $this->packageRoot = dirname(__DIR__);
        // Asked of Git, not of the filesystem: in a linked worktree `.git` is a
        // file, and shape-testing it skipped this replay in a valid checkout.
        if (!GitWorkTree::detected($this->packageRoot)) {
            self::markTestSkipped('The package Git history is required for the historical replay.');
        }
        if (!$this->hasRevision('d0ce7ba')) {
            self::markTestSkipped('The package checkout does not contain the public replay revision.');
        }
        if (!is_file('/usr/bin/perl')) {
            self::markTestSkipped('The Linux baseline requires /usr/bin/perl.');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function testReplaysPublicPackageFixWithAutoAndLinuxBaseline(): void
    {
        $commit = 'd0ce7ba';
        $path = 'src/Edit/CommandEditRunner.php';
        $target = 'voku\\AgentLoop\\Edit\\CommandEditRunner::run';
        $old = "if (!\$running && \$observedExitCode === null && is_int(\$status['exitcode']) && \$status['exitcode'] >= 0) {";
        $new = "if (!\$running && \$observedExitCode === null && \$status['exitcode'] >= 0) {";

        $parentSource = $this->gitShow($commit . '^', $path);
        $expectedSource = $this->gitShow($commit, $path);
        self::assertSame(1, substr_count($parentSource, $old));

        $agentRoot = $this->temporaryDirectory('agent-loop-public-replay-');
        $agentPath = $this->writeSource($agentRoot, $path, $parentSource);
        $outputDirectory = $agentRoot . '/execution';

        $command = new EditCommand($this->packageRoot);
        ob_start();
        $exit = $command->run([
            $target,
            '--task=public-replay-' . $commit,
            '--map-root=' . $agentRoot,
            '--map-index=' . $agentRoot . '/map.json',
            '--map-paths=src',
            '--output-dir=' . $outputDirectory,
            '--runner=auto',
            '--replace-old=' . $old,
            '--replace-new=' . $new,
            '--',
            'Remove the redundant strict type check exactly once.',
        ]);
        ob_end_clean();

        self::assertSame(0, $exit);
        self::assertSame($expectedSource, (string) file_get_contents($agentPath));

        $execution = $this->execution($outputDirectory);
        self::assertSame('runner_succeeded', $execution['status']);
        self::assertSame('mechanical', $execution['runner']['name']);
        self::assertSame(0, $execution['runner']['model_input_tokens']);
        self::assertSame(0, $execution['runner']['model_tool_calls']);
        self::assertSame('auto', $execution['routing']['requested_runner']);
        self::assertSame('mechanical', $execution['routing']['selected_runner']);
        self::assertFalse($execution['routing']['external_model_invoked']);

        $linuxRoot = $this->temporaryDirectory('agent-loop-linux-baseline-');
        $linuxPath = $this->writeSource($linuxRoot, $path, $parentSource);
        self::assertSame(1, $this->replaceWithLinuxBaseline($linuxPath, $old, $new));
        self::assertSame($expectedSource, (string) file_get_contents($linuxPath));
    }

    private function gitShow(string $revision, string $path): string
    {
        $pipes = [];
        $process = proc_open(['git', 'show', $revision . ':' . $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->packageRoot);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to read package history.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Unable to read ' . $revision . ':' . $path . ': ' . trim((string) $stderr));
        }

        return $stdout;
    }

    private function hasRevision(string $revision): bool
    {
        $pipes = [];
        $process = proc_open(['git', 'cat-file', '-e', $revision . '^{commit}'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->packageRoot);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0;
    }

    private function temporaryDirectory(string $prefix): string
    {
        $path = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($path, 0o775, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create temporary replay directory: ' . $path);
        }
        $this->temporaryDirectories[] = $path;

        return $path;
    }

    private function writeSource(string $root, string $path, string $content): string
    {
        $target = $root . '/' . $path;
        if (!mkdir(dirname($target), 0o775, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Unable to create replay source directory.');
        }
        if (file_put_contents($target, $content) === false) {
            throw new RuntimeException('Unable to write replay source.');
        }

        return $target;
    }

    private function replaceWithLinuxBaseline(string $path, string $old, string $new): int
    {
        $script = '$old = $ENV{"OLD"}; $new = $ENV{"NEW"}; $count = () = $_ =~ /\\Q$old\\E/g; s/\\Q$old\\E/$new/g; print STDERR $count;';
        $pipes = [];
        $process = proc_open(['/usr/bin/perl', '-0777', '-p', '-i', '-e', $script, $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['OLD' => $old, 'NEW' => $new]);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Linux replacement baseline.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('Linux replacement baseline failed: ' . trim((string) $stdout . "\n" . (string) $stderr));
        }
        if (!is_string($stderr) || preg_match('/\A\d+\z/', $stderr) !== 1) {
            throw new RuntimeException('Linux replacement baseline did not report its replacement count.');
        }

        return (int) $stderr;
    }

    /** @return array<string, mixed> */
    private function execution(string $outputDirectory): array
    {
        $json = file_get_contents($outputDirectory . '/execution.json');
        self::assertIsString($json);
        $execution = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($execution);

        return $execution;
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
