<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\InitHostStatusCommand;
use voku\AgentLoop\Init\InitInstallAssetsCommand;
use voku\AgentLoop\Init\InitSyncPolicyCommand;

final class InitHostStatusCommandTest extends TestCase
{
    private string $root;

    private string $binRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-host-status-' . bin2hex(random_bytes(6));
        $this->binRoot = $this->root . '/bin';
        if (!mkdir($this->binRoot, 0o775, true) && !is_dir($this->binRoot)) {
            throw new RuntimeException('Unable to create host-status fixture root.');
        }
        $this->createExecutable('opencode');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testOpenCodeSelfDiscoveryConvergesThroughCanonicalRepositoryActions(): void
    {
        $initial = $this->hostStatus();
        self::assertSame('opencode', $initial['host']);
        self::assertSame('auto', $initial['selection']);
        self::assertSame('available', $initial['runtime']['status'] ?? null);
        self::assertSame([
            'instructions' => 'missing',
            'skills' => 'missing',
            'subagents' => 'missing',
            'policy' => 'missing',
        ], $initial['integration']);
        self::assertSame('command', $initial['next_action_kind']);
        self::assertSame('vendor/bin/agent-loop init install-assets --agent=opencode', $initial['next_action']);

        $install = $this->capture(static fn (): int => (new InitInstallAssetsCommand($this->root))->run(['--agent=opencode']));
        self::assertSame(0, $install['exit'], $install['output']);
        self::assertFileExists($this->root . '/AGENTS.md');
        self::assertFileExists($this->root . '/.opencode/skills/.agent-loop-manifest.json');
        self::assertFileExists($this->root . '/.opencode/agents/.agent-loop-manifest.json');
        self::assertFileExists($this->root . '/.opencode/agents/agent-loop-investigator.md');

        $afterAssets = $this->hostStatus();
        self::assertSame([
            'instructions' => 'ready',
            'skills' => 'ready',
            'subagents' => 'ready',
            'policy' => 'missing',
        ], $afterAssets['integration']);
        self::assertSame('command', $afterAssets['next_action_kind']);
        self::assertSame('vendor/bin/agent-loop init sync-policy --agent=opencode', $afterAssets['next_action']);

        $policy = $this->capture(static fn (): int => (new InitSyncPolicyCommand($this->root))->run(['--agent=opencode']));
        self::assertSame(0, $policy['exit'], $policy['output']);
        self::assertFileExists($this->root . '/opencode.json');

        $ready = $this->hostStatus();
        self::assertSame('ready', $ready['integration']['policy'] ?? null);
        self::assertSame('none', $ready['next_action_kind']);
        self::assertNull($ready['next_action']);
        self::assertIsString($ready['runtime_boundary']);
        self::assertStringContainsString('--auto', $ready['runtime_boundary']);
    }

    public function testMultipleVisibleHostsRequireExplicitSelection(): void
    {
        $this->createExecutable('claude');

        $status = $this->hostStatus();

        self::assertNull($status['host']);
        self::assertSame('ambiguous', $status['selection']);
        self::assertSame('decision_required', $status['next_action_kind']);
        self::assertStringContainsString('--agent=<claude|opencode>', (string) $status['next_action']);
    }

    /**
     * @return array{
     *     schema_version: int,
     *     host: string|null,
     *     selection: string,
     *     runtime: array<string, mixed>|null,
     *     integration: array<string, mixed>|null,
     *     policy_detail: string|null,
     *     policy_path: string|null,
     *     runtime_boundary: string|null,
     *     next_action_kind: string,
     *     next_action: string|null
     * }
     */
    private function hostStatus(): array
    {
        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        ob_start();
        try {
            $exit = (new InitHostStatusCommand($this->root, $probe))->run(['--format=json']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, $output);

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Host status did not return valid JSON: ' . $exception->getMessage() . "\n" . $output);
        }
        self::assertIsArray($decoded);

        /** @var array{schema_version:int,host:string|null,selection:string,runtime:array<string,mixed>|null,integration:array<string,mixed>|null,policy_detail:string|null,policy_path:string|null,runtime_boundary:string|null,next_action_kind:string,next_action:string|null} $decoded */
        return $decoded;
    }

    /** @return array{exit: int, output: string} */
    private function capture(callable $callback): array
    {
        ob_start();
        try {
            $exit = $callback();
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return ['exit' => $exit, 'output' => $output];
    }

    private function createExecutable(string $name): void
    {
        $path = $this->binRoot . DIRECTORY_SEPARATOR . self::executableFileName($name);
        if (file_put_contents($path, "#!/bin/sh\nexit 0\n") === false) {
            throw new RuntimeException('Unable to create fake host executable: ' . $path);
        }
        if (DIRECTORY_SEPARATOR === '/' && !chmod($path, 0o755)) {
            throw new RuntimeException('Unable to make fake host executable executable: ' . $path);
        }
    }

    private static function executableFileName(string $name): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? $name . '.EXE' : $name;
    }

    private static function pathExt(): ?string
    {
        return DIRECTORY_SEPARATOR === '\\' ? '.EXE' : null;
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
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
