<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitSyncToolsCommand;

/**
 * @internal
 */
final class InitSyncToolsCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-sync-tools-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/docs/agents/tools/slop-scan', 0o775, true);
        mkdir($this->root . '/docs/agents/tools/itp-context', 0o775, true);
        file_put_contents($this->root . '/docs/agents/tools/slop-scan/composer.json', '{"require":{"voku/slop-scan":"^0.1.4"}}');
        file_put_contents($this->root . '/docs/agents/tools/slop-scan/slop-scan.php', "#!/usr/bin/env php\n");
        chmod($this->root . '/docs/agents/tools/slop-scan/slop-scan.php', 0o755);
        file_put_contents($this->root . '/docs/agents/tools/itp-context/composer.json', '{"require":{"voku/itp-context":"^0.3.0"}}');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testInstallsEveryToolProjectAndRecordsThemAsManaged(): void
    {
        $result = $this->runSync([]);

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/tools/slop-scan/composer.json');
        self::assertFileExists($this->root . '/tools/slop-scan/slop-scan.php');
        self::assertFileExists($this->root . '/tools/itp-context/composer.json');

        $manifest = json_decode((string) file_get_contents($this->root . '/tools/.agent-loop-manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertSame('tools', $manifest['kind']);
        self::assertSame(
            ['itp-context/composer.json', 'slop-scan/composer.json', 'slop-scan/slop-scan.php'],
            $manifest['entries'],
        );
    }

    /** A runner that is not executable is a runner an agent cannot invoke. */
    public function testRunnerKeepsItsExecutableBit(): void
    {
        $this->runSync([]);

        self::assertTrue(is_executable($this->root . '/tools/slop-scan/slop-scan.php'));
    }

    /** Resolving dependencies reaches the network; that stays the operator's call. */
    public function testNeverInstallsDependenciesButNamesTheCommand(): void
    {
        $result = $this->runSync([]);

        self::assertDirectoryDoesNotExist($this->root . '/tools/slop-scan/vendor');
        self::assertStringContainsString('composer install --working-dir=tools/slop-scan', $result['output']);
        self::assertStringContainsString('composer install --working-dir=tools/itp-context', $result['output']);
        self::assertStringContainsString('agent-loop init tools --refresh', $result['output']);
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->runSync(['--dry-run']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('would write tools/slop-scan/composer.json', $result['output']);
        self::assertFileDoesNotExist($this->root . '/tools/slop-scan/composer.json');
        self::assertFileDoesNotExist($this->root . '/tools/.agent-loop-manifest.json');
    }

    public function testUnmanagedTargetIsNotOverwritten(): void
    {
        mkdir($this->root . '/tools/slop-scan', 0o775, true);
        file_put_contents($this->root . '/tools/slop-scan/composer.json', '{"require":{"voku/slop-scan":"0.1.0"}}');

        $result = $this->runSync([]);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('unmanaged target already exists', $result['output']);
        self::assertStringContainsString(
            '0.1.0',
            (string) file_get_contents($this->root . '/tools/slop-scan/composer.json'),
            'a pinned tool project the operator wrote is not silently replaced',
        );
    }

    public function testForceOverwritesAnUnmanagedTarget(): void
    {
        mkdir($this->root . '/tools/slop-scan', 0o775, true);
        file_put_contents($this->root . '/tools/slop-scan/composer.json', '{"require":{"voku/slop-scan":"0.1.0"}}');

        $result = $this->runSync(['--force']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('^0.1.4', (string) file_get_contents($this->root . '/tools/slop-scan/composer.json'));
    }

    public function testAdoptExistingRecordsWithoutTouchingContent(): void
    {
        mkdir($this->root . '/tools/slop-scan', 0o775, true);
        file_put_contents($this->root . '/tools/slop-scan/composer.json', '{"require":{"voku/slop-scan":"0.1.0"}}');

        $result = $this->runSync(['--adopt-existing']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('adopted tools/slop-scan/composer.json', $result['output']);
        self::assertStringContainsString('0.1.0', (string) file_get_contents($this->root . '/tools/slop-scan/composer.json'));

        $manifest = json_decode((string) file_get_contents($this->root . '/tools/.agent-loop-manifest.json'), true);
        self::assertIsArray($manifest);
        self::assertContains('slop-scan/composer.json', $manifest['entries']);
    }

    public function testManagedEntryThatLeftThePackageIsRemoved(): void
    {
        $this->runSync([]);
        unlink($this->root . '/docs/agents/tools/itp-context/composer.json');
        rmdir($this->root . '/docs/agents/tools/itp-context');

        $result = $this->runSync([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('removed tools/itp-context/composer.json', $result['output']);
        self::assertFileDoesNotExist($this->root . '/tools/itp-context/composer.json');
    }

    public function testCustomToolsDirIsHonored(): void
    {
        $result = $this->runSync(['--tools-dir=dev-tools']);

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/dev-tools/slop-scan/composer.json');
        self::assertStringContainsString('composer install --working-dir=dev-tools/slop-scan', $result['output']);
    }

    public function testMissingToolRootFails(): void
    {
        $result = $this->runSync(['--tools-root=does/not/exist']);

        self::assertSame(1, $result['exit']);
    }

    public function testUnknownOptionFails(): void
    {
        $result = $this->runSync(['--bogus']);

        self::assertSame(1, $result['exit']);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runSync(array $tokens): array
    {
        $command = new InitSyncToolsCommand($this->root);

        ob_start();
        $exit = $command->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
