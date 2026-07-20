<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitToolsCommand;

/**
 * @internal
 */
final class InitToolsCommandTest extends TestCase
{
    private string $root;

    private string $fakeBinDir;

    private string|false $originalPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-tools-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        $this->fakeBinDir = sys_get_temp_dir() . '/agent-loop-init-tools-bin-' . bin2hex(random_bytes(6));
        mkdir($this->fakeBinDir, 0o775, true);

        $this->originalPath = getenv('PATH');
    }

    protected function tearDown(): void
    {
        if ($this->originalPath !== false) {
            putenv('PATH=' . $this->originalPath);
        }

        $this->removeDirectory($this->root);
        $this->removeDirectory($this->fakeBinDir);
    }

    public function testProbeReportsAvailableToolAndWritesCache(): void
    {
        $this->makeFakeExecutable('rg');
        putenv('PATH=' . $this->fakeBinDir);

        $result = $this->runTools([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] rg: available (' . $this->fakeBinDir . '/rg)', $result['output']);
        self::assertFileExists($this->root . '/.agent-loop/tool-inventory.json');

        $cache = json_decode((string) file_get_contents($this->root . '/.agent-loop/tool-inventory.json'), true);
        self::assertIsArray($cache);
        self::assertTrue($cache['tools']['rg']['available']);
    }

    public function testProbeReportsMissingToolAsWarning(): void
    {
        putenv('PATH=' . $this->fakeBinDir);

        $result = $this->runTools([]);

        self::assertStringContainsString('[WARN] rg: not found in PATH', $result['output']);
    }

    public function testProbeReportsMissingAgentMapIndex(): void
    {
        putenv('PATH=' . $this->fakeBinDir);

        $result = $this->runTools([]);

        self::assertStringContainsString('[INFO] agent-map index: not built (.agent-map/php-symbols.json)', $result['output']);
    }

    public function testProbeReportsPresentAgentMapIndex(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        mkdir($this->root . '/.agent-map', 0o775, true);
        file_put_contents($this->root . '/.agent-map/php-symbols.json', '{}');

        $result = $this->runTools([]);

        self::assertStringContainsString('[INFO] agent-map index: present (.agent-map/php-symbols.json,', $result['output']);
    }

    public function testFreshCacheIsReusedWithoutReprobing(): void
    {
        putenv('PATH=' . $this->fakeBinDir);

        $first = $this->runTools([]);
        self::assertStringContainsString('cache: refreshed', $first['output']);

        $this->makeFakeExecutable('rg');

        $second = $this->runTools([]);
        self::assertStringContainsString('cache: reused', $second['output']);
        self::assertStringContainsString('[WARN] rg: not found in PATH', $second['output'], 'reused cache must not silently re-probe');
    }

    public function testRefreshForcesReprobeEvenWithFreshCache(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        $this->runTools([]);

        $this->makeFakeExecutable('rg');

        $result = $this->runTools(['--refresh']);

        self::assertStringContainsString('cache: refreshed', $result['output']);
        self::assertStringContainsString('[OK] rg: available', $result['output']);
    }

    public function testMaxAgeZeroForcesReprobeOnEveryRun(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        $this->runTools(['--max-age=0']);

        $this->makeFakeExecutable('rg');

        $result = $this->runTools(['--max-age=0']);

        self::assertStringContainsString('cache: refreshed', $result['output']);
        self::assertStringContainsString('[OK] rg: available', $result['output']);
    }

    public function testCustomCachePathIsHonored(): void
    {
        putenv('PATH=' . $this->fakeBinDir);

        $result = $this->runTools(['--cache=custom/tools.json']);

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/custom/tools.json');
        self::assertFileDoesNotExist($this->root . '/.agent-loop/tool-inventory.json');
        self::assertStringContainsString('custom/tools.json', $result['output']);
    }

    public function testMalformedCacheFileIsTreatedAsMissing(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/tool-inventory.json', '{not valid json');

        $result = $this->runTools([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('cache: refreshed', $result['output']);
    }

    public function testUnknownOptionFails(): void
    {
        $result = $this->runTools(['--bogus']);

        self::assertSame(1, $result['exit']);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runTools(array $tokens): array
    {
        $command = new InitToolsCommand($this->root);

        ob_start();
        $exit = $command->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function makeFakeExecutable(string $name): void
    {
        $path = $this->fakeBinDir . '/' . $name;
        file_put_contents($path, "#!/bin/sh\necho fake-{$name}\n");
        chmod($path, 0o755);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
