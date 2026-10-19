<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Init\HostRuntimeProbe;

final class HostRuntimeProbeTest extends TestCase
{
    private string $binRoot;

    protected function setUp(): void
    {
        $this->binRoot = sys_get_temp_dir() . '/agent-loop-host-runtime-' . bin2hex(random_bytes(4));
        if (!mkdir($this->binRoot, 0o775, true) && !is_dir($this->binRoot)) {
            throw new RuntimeException('Unable to create fake binary root: ' . $this->binRoot);
        }
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->binRoot) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $this->binRoot . '/' . $entry;
            if (is_file($path) && !unlink($path)) {
                throw new RuntimeException('Unable to remove fake host binary: ' . $path);
            }
        }
        if (is_dir($this->binRoot) && !rmdir($this->binRoot)) {
            throw new RuntimeException('Unable to remove fake binary root: ' . $this->binRoot);
        }
    }

    public function testDetectsSupportedCliNamesFromProvidedPath(): void
    {
        foreach (['codex', 'claude', 'opencode', 'copilot', 'gemini'] as $command) {
            $this->createExecutable($command);
        }

        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());

        foreach (['codex', 'claude', 'opencode', 'copilot', 'gemini'] as $agent) {
            $result = $probe->probe($agent);
            self::assertSame('available', $result['status']);
            self::assertSame($agent, $result['command']);
            self::assertSame($this->executablePath($agent), $result['path']);
        }
    }

    public function testEmptyPathEntryResolvesAgainstCurrentDirectory(): void
    {
        $this->createExecutable('codex');
        $previousDirectory = getcwd();
        if (!is_string($previousDirectory) || !chdir($this->binRoot)) {
            throw new RuntimeException('Unable to enter fake binary root.');
        }

        try {
            $probe = new HostRuntimeProbe(PATH_SEPARATOR . $this->binRoot . '/missing', self::pathExt());
            $result = $probe->probe('codex');

            self::assertSame('available', $result['status']);
            self::assertSame('.' . DIRECTORY_SEPARATOR . self::executableFileName('codex'), $result['path']);
        } finally {
            if (!chdir($previousDirectory)) {
                throw new RuntimeException('Unable to restore working directory.');
            }
        }
    }

    public function testReportsMissingRuntimeWithoutUpgradingAdapterTruth(): void
    {
        $result = (new HostRuntimeProbe($this->binRoot, self::pathExt()))->probe('opencode');

        self::assertSame('missing', $result['status']);
        self::assertSame('opencode', $result['command']);
        self::assertNull($result['path']);
    }

    public function testCursorIsExplicitlyUnprobedEvenWhenGenericAgentExecutableExists(): void
    {
        $this->createExecutable('agent');

        $result = (new HostRuntimeProbe($this->binRoot, self::pathExt()))->probe('cursor');

        self::assertSame('unprobed', $result['status']);
        self::assertNull($result['command']);
        self::assertNull($result['path']);
    }

    public function testAntigravityIsExplicitlyUnprobed(): void
    {
        $result = (new HostRuntimeProbe($this->binRoot, self::pathExt()))->probe('antigravity');

        self::assertSame('unprobed', $result['status']);
        self::assertNull($result['command']);
        self::assertNull($result['path']);
    }

    public function testVersionProbeReadsOnlyAValidHostVersionFromTheResolvedBinary(): void
    {
        $this->createExecutableWithOutput('codex', "#!/bin/sh\nprintf '%s\\n' 'codex-cli 0.157.0'\n");
        $this->createExecutableWithOutput('claude', "#!/bin/sh\nprintf '%s\\n' '2.1.287 (Claude Code)'\n");
        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        $runtime = $probe->probe('codex');

        self::assertSame('available', $runtime['status']);
        self::assertSame([
            'status' => 'available',
            'version' => '0.157.0',
        ], $probe->probeVersion('codex', $runtime['path']));
        self::assertSame(
            ['status' => 'available', 'version' => '2.1.287'],
            $probe->probeVersion('claude', $this->executablePath('claude')),
        );
    }

    public function testVersionProbeKeepsMalformedFailedMissingAndUnsupportedStatesExplicit(): void
    {
        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        self::assertSame(['status' => 'unavailable', 'version' => null], $probe->probeVersion('codex', null));
        self::assertSame(['status' => 'unprobed', 'version' => null], $probe->probeVersion('cursor', null));

        $this->createExecutableWithOutput('codex', "#!/bin/sh\nprintf '%s\\n' 'version unknown'\n");
        self::assertSame(
            ['status' => 'unavailable', 'version' => null],
            $probe->probeVersion('codex', $this->executablePath('codex')),
        );

        $this->createExecutableWithOutput('claude', "#!/bin/sh\nprintf '%s\\n' '2.1.287'\n");
        self::assertSame(
            ['status' => 'unavailable', 'version' => null],
            $probe->probeVersion('claude', $this->executablePath('claude')),
        );

        $this->createExecutableWithOutput('codex', "#!/bin/sh\nsleep 5\nprintf '%s\\n' 'codex-cli 0.157.0'\n");
        self::assertSame(
            ['status' => 'unavailable', 'version' => null],
            $probe->probeVersion('codex', $this->executablePath('codex')),
        );

        $this->createExecutableWithOutput('claude', "#!/bin/sh\nexit 1\n");
        self::assertSame(
            ['status' => 'unavailable', 'version' => null],
            $probe->probeVersion('claude', $this->executablePath('claude')),
        );
    }

    private function createExecutable(string $name): void
    {
        $this->createExecutableWithOutput($name, "#!/bin/sh\nexit 0\n");
    }

    private function createExecutableWithOutput(string $name, string $contents): void
    {
        $path = $this->executablePath($name);
        if (file_put_contents($path, $contents) === false) {
            throw new RuntimeException('Unable to create fake host binary: ' . $path);
        }
        if (DIRECTORY_SEPARATOR === '/' && !chmod($path, 0o755)) {
            throw new RuntimeException('Unable to make fake host binary executable: ' . $path);
        }
    }

    private function executablePath(string $name): string
    {
        return $this->binRoot . DIRECTORY_SEPARATOR . self::executableFileName($name);
    }

    private static function executableFileName(string $name): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? $name . '.EXE' : $name;
    }

    private static function pathExt(): ?string
    {
        return DIRECTORY_SEPARATOR === '\\' ? '.EXE' : null;
    }
}
