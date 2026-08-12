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
        self::assertStringNotContainsString('rtk', strtolower($result['output']));
        self::assertFileExists($this->root . '/.agent-loop/tool-inventory.json');

        $cache = json_decode((string) file_get_contents($this->root . '/.agent-loop/tool-inventory.json'), true);
        self::assertIsArray($cache);
        self::assertTrue($cache['tools']['rg']['available']);
        self::assertSame(
            ['rg', 'git', 'php', 'composer', 'docker', 'itp-context', 'slop-scan'],
            array_keys($cache['tools']),
        );
    }

    public function testExternalEvidenceToolIsFoundInAnIsolatedToolProject(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        $this->makeProjectFile('tools/slop-scan/vendor/bin/slop-scan.php');

        $result = $this->runTools([]);

        self::assertStringContainsString(
            '[OK] slop-scan: available (' . $this->root . '/tools/slop-scan/vendor/bin/slop-scan.php)',
            $result['output'],
        );
    }

    public function testExternalEvidenceToolIsFoundInTheProjectVendorBin(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        $this->makeProjectFile('vendor/bin/itp-context-query');

        $result = $this->runTools([]);

        self::assertStringContainsString(
            '[OK] itp-context: available (' . $this->root . '/vendor/bin/itp-context-query)',
            $result['output'],
        );
    }

    public function testPinnedProjectInstallationWinsOverAnAmbientPathBuild(): void
    {
        $this->makeFakeExecutable('slop-scan');
        putenv('PATH=' . $this->fakeBinDir);
        $this->makeProjectFile('tools/slop-scan/vendor/bin/slop-scan.php');

        $result = $this->runTools([]);

        self::assertStringContainsString(
            '[OK] slop-scan: available (' . $this->root . '/tools/slop-scan/vendor/bin/slop-scan.php)',
            $result['output'],
            'a repository that pinned the tool meant that version, not whichever build is ambient',
        );
    }

    public function testExternalEvidenceToolFallsBackToPath(): void
    {
        $this->makeFakeExecutable('slop-scan');
        putenv('PATH=' . $this->fakeBinDir);

        $result = $this->runTools([]);

        self::assertStringContainsString('[OK] slop-scan: available (' . $this->fakeBinDir . '/slop-scan)', $result['output']);
    }

    public function testMissingExternalEvidenceToolIsReportedWithoutWarning(): void
    {
        putenv('PATH=' . $this->fakeBinDir);

        $result = $this->runTools([]);

        self::assertStringContainsString('[INFO] itp-context: not installed (external evidence tool)', $result['output']);
        self::assertStringContainsString('[INFO] slop-scan: not installed (external evidence tool)', $result['output']);
        self::assertStringNotContainsString('[WARN] slop-scan', $result['output']);
    }

    public function testCacheWrittenBeforeAToolWasKnownIsReprobed(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/tool-inventory.json',
            (string) json_encode([
                'generated_at' => date(\DATE_ATOM),
                'max_age_seconds' => 3600,
                'tools' => ['rg' => ['available' => false, 'path' => null]],
                'agent_map_index' => ['present' => false, 'path' => '.agent-map/php-symbols.json', 'age_seconds' => null],
            ]),
        );
        $this->makeProjectFile('tools/slop-scan/vendor/bin/slop-scan.php');

        $result = $this->runTools([]);

        self::assertStringContainsString('cache: refreshed', $result['output']);
        self::assertStringContainsString('[OK] slop-scan: available', $result['output']);
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

        self::assertStringContainsString('[INFO] agent-map index: not built (.agent-loop/map/php-symbols.json)', $result['output']);
    }

    public function testProbeReportsPresentAgentMapIndex(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/map/php-symbols.json', '{}');

        $result = $this->runTools([]);

        self::assertStringContainsString('[INFO] agent-map index: present (.agent-loop/map/php-symbols.json,', $result['output']);
    }

    /**
     * The probe kept its own literal for the map index and answered from the
     * pre-consolidation location, so a built index was reported as never built.
     */
    public function testBuiltMapIndexIsFoundWhereTheLayoutOwnerPutsIt(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        mkdir($this->root . '/.agent-map', 0o775, true);
        file_put_contents($this->root . '/.agent-map/php-symbols.json', '{}');

        $result = $this->runTools([]);

        self::assertStringContainsString(
            '[INFO] agent-map index: not built',
            $result['output'],
            'the retired location is not the map index',
        );
    }

    public function testMapIndexFollowsAConfiguredStateRoot(): void
    {
        putenv('PATH=' . $this->fakeBinDir);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            (string) json_encode(['paths' => ['state_root' => 'custom-state']]),
        );
        mkdir($this->root . '/custom-state/map', 0o775, true);
        file_put_contents($this->root . '/custom-state/map/php-symbols.json', '{}');

        $result = $this->runTools([]);

        self::assertStringContainsString('[INFO] agent-map index: present (custom-state/map/php-symbols.json,', $result['output']);
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

    /** An installed binary inside the project, as Composer would place it. */
    private function makeProjectFile(string $relativePath): void
    {
        $path = $this->root . '/' . $relativePath;
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0o775, true);
        }

        file_put_contents($path, "#!/usr/bin/env php\n");
        chmod($path, 0o755);
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
