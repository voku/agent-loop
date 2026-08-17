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
        foreach (['codex', 'claude', 'copilot', 'gemini'] as $command) {
            $this->createExecutable($command);
        }

        $probe = new HostRuntimeProbe($this->binRoot);

        foreach (['codex', 'claude', 'copilot', 'gemini'] as $agent) {
            $result = $probe->probe($agent);
            self::assertSame('available', $result['status']);
            self::assertSame($agent, $result['command']);
            self::assertSame($this->binRoot . DIRECTORY_SEPARATOR . $agent, $result['path']);
        }
    }

    public function testReportsMissingRuntimeWithoutUpgradingAdapterTruth(): void
    {
        $result = (new HostRuntimeProbe($this->binRoot))->probe('gemini');

        self::assertSame('missing', $result['status']);
        self::assertSame('gemini', $result['command']);
        self::assertNull($result['path']);
    }

    public function testAntigravityIsExplicitlyUnprobed(): void
    {
        $result = (new HostRuntimeProbe($this->binRoot))->probe('antigravity');

        self::assertSame('unprobed', $result['status']);
        self::assertNull($result['command']);
        self::assertNull($result['path']);
    }

    private function createExecutable(string $name): void
    {
        $path = $this->binRoot . '/' . $name;
        if (file_put_contents($path, "#!/bin/sh\nexit 0\n") === false) {
            throw new RuntimeException('Unable to create fake host binary: ' . $path);
        }
        if (DIRECTORY_SEPARATOR === '/' && !chmod($path, 0o755)) {
            throw new RuntimeException('Unable to make fake host binary executable: ' . $path);
        }
    }
}
