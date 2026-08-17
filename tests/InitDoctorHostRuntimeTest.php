<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Init\HostCapability;
use voku\AgentLoop\Init\HostCapabilityMatrix;
use voku\AgentLoop\Init\HostCapabilityStatus;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\InitDoctorCommand;

final class InitDoctorHostRuntimeTest extends TestCase
{
    private string $root;
    private string $binRoot;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->root = sys_get_temp_dir() . '/agent-loop-doctor-runtime-' . $suffix;
        $this->binRoot = $this->root . '/bin';
        if (!mkdir($this->binRoot, 0o775, true) && !is_dir($this->binRoot)) {
            throw new RuntimeException('Unable to create test root: ' . $this->binRoot);
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
        if (is_dir($this->root) && !rmdir($this->root)) {
            throw new RuntimeException('Unable to remove test root: ' . $this->root);
        }
    }

    public function testDoctorReportsRuntimeDiscoverySeparatelyFromAdapterSupport(): void
    {
        $this->createExecutable('gemini');

        $output = $this->runDoctor();

        self::assertStringContainsString(
            '[INFO] Host runtime [gemini]: available; command=gemini; path=' . $this->executablePath('gemini'),
            $output,
        );
        self::assertStringContainsString('[INFO] Host runtime [codex]: missing; command=codex', $output);
        self::assertStringContainsString(
            '[INFO] Host runtime [antigravity]: unprobed; evidence=no stable CLI probe configured',
            $output,
        );
        self::assertStringContainsString(
            '[INFO] Host capabilities [gemini]: skill-projection=supported, subagent-projection=supported',
            $output,
        );
    }

    public function testBinaryDiscoveryDoesNotUpgradeUnverifiedLifecycleCapabilities(): void
    {
        $this->createExecutable('codex');

        $output = $this->runDoctor();

        self::assertStringContainsString(
            '[INFO] Host runtime [codex]: available; command=codex; path=' . $this->executablePath('codex'),
            $output,
        );
        self::assertStringContainsString(
            'Host capability evidence [codex/session-bootstrap]: mechanism=Codex hooks.json + repository-local command hooks; evidence=adapter-declared;live-runtime-unverified',
            $output,
        );
        self::assertSame(
            HostCapabilityStatus::Degraded,
            HostCapabilityMatrix::status('codex', HostCapability::SessionBootstrap),
        );
        self::assertSame(
            'adapter-declared;live-runtime-unverified',
            HostCapabilityMatrix::describe('codex', HostCapability::SessionBootstrap)['evidence'],
        );
        self::assertSame(
            'adapter-declared',
            HostCapabilityMatrix::describe('gemini', HostCapability::SkillProjection)['evidence'],
        );
    }

    private function runDoctor(): string
    {
        ob_start();
        $exit = (new InitDoctorCommand(
            $this->root,
            new HostRuntimeProbe($this->binRoot, self::pathExt()),
        ))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);

        return $output;
    }

    private function createExecutable(string $name): void
    {
        $path = $this->executablePath($name);
        if (file_put_contents($path, "#!/bin/sh\nexit 0\n") === false) {
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
