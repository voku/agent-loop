<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\InitConfigLoader;

final class ReleaseSetDogfoodFixtureTest extends TestCase
{
    private string $temporaryRoot;

    protected function setUp(): void
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/agent-loop-release-set-fixture-' . bin2hex(random_bytes(4));
        mkdir($this->temporaryRoot);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->temporaryRoot);
    }

    public function testRunnerAndFixturePhpFilesPassPhpLint(): void
    {
        $repositoryRoot = dirname(__DIR__);
        $files = [
            $repositoryRoot . '/tools/agent-discipline-dogfood.php',
            $repositoryRoot . '/tools/release-set-dogfood.php',
        ];
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $repositoryRoot . '/tests/fixtures/release-set-consumer',
                RecursiveDirectoryIterator::SKIP_DOTS,
            ),
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            [$exitCode, $stdout, $stderr] = $this->executeCommand([PHP_BINARY, '-l', $file], $repositoryRoot);
            self::assertSame(0, $exitCode, $file . " failed PHP lint:\n" . $stdout . $stderr);
        }
    }

    public function testFixtureDoesNotOverrideCanonicalRecallRoot(): void
    {
        $fixture = dirname(__DIR__) . '/tests/fixtures/release-set-consumer/.agent-loop/init.json';
        mkdir($this->temporaryRoot . '/.agent-loop', 0o775, true);
        copy($fixture, $this->temporaryRoot . '/.agent-loop/init.json');

        $config = (new InitConfigLoader($this->temporaryRoot))->load('.agent-loop/init.json');

        self::assertSame([], $config['warnings']);
        self::assertArrayNotHasKey('recall_root', $config['paths']);
    }

    public function testFixtureEditIsExactAndDeterministic(): void
    {
        $sourceRoot = dirname(__DIR__) . '/tests/fixtures/release-set-consumer';
        mkdir($this->temporaryRoot . '/src', 0o775, true);
        mkdir($this->temporaryRoot . '/tools', 0o775, true);
        copy($sourceRoot . '/src/RetryPolicy.php', $this->temporaryRoot . '/src/RetryPolicy.php');
        copy($sourceRoot . '/tools/apply-change.php', $this->temporaryRoot . '/tools/apply-change.php');

        [$exitCode, $stdout, $stderr] = $this->executeCommand(
            [PHP_BINARY, $this->temporaryRoot . '/tools/apply-change.php'],
            $this->temporaryRoot,
        );

        self::assertSame(0, $exitCode, $stdout . $stderr);
        self::assertStringContainsString('fixture edit applied', $stdout);
        $updated = file_get_contents($this->temporaryRoot . '/src/RetryPolicy.php');
        self::assertIsString($updated);
        self::assertStringContainsString('return 200 * $attempt;', $updated);
        self::assertStringNotContainsString('return 100 * $attempt;', $updated);

        [$secondExit, , $secondStderr] = $this->executeCommand(
            [PHP_BINARY, $this->temporaryRoot . '/tools/apply-change.php'],
            $this->temporaryRoot,
        );
        self::assertSame(1, $secondExit);
        self::assertStringContainsString('exactly once', $secondStderr);
    }

    /**
     * @param list<string> $command
     * @return array{0: int, 1: string, 2: string}
     */
    private function executeCommand(array $command, string $workingDirectory): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start test process.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            $exitCode,
            is_string($stdout) ? $stdout : '',
            is_string($stderr) ? $stderr : '',
        ];
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($directory);
    }
}
