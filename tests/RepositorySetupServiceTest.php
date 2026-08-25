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
use voku\AgentLoop\Init\RepositorySetupService;

final class RepositorySetupServiceTest extends TestCase
{
    private string $root;

    private string $binRoot;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-service-' . bin2hex(random_bytes(6));
        $this->binRoot = $this->root . '/bin';
        if (!mkdir($this->binRoot, 0o775, true) && !is_dir($this->binRoot)) {
            throw new RuntimeException('Unable to create setup-service fixture root.');
        }
        $this->createExecutable('opencode');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTypedProjectionIsTheSameContractRenderedByHostStatusJson(): void
    {
        $probe = new HostRuntimeProbe($this->binRoot, self::pathExt());
        $projection = (new RepositorySetupService($this->root, $probe))->overview();

        self::assertSame('opencode', $projection->host);
        self::assertSame('auto', $projection->selection);
        self::assertSame('available', $projection->runtime?->status);
        self::assertSame('missing', $projection->integration?->instructions);
        self::assertSame('missing', $projection->integration?->skills);
        self::assertSame('missing', $projection->integration?->subagents);
        self::assertSame('missing', $projection->integration?->policy);
        self::assertSame('not_declared', $projection->integration?->gitIntegration);
        self::assertSame('command', $projection->nextActionKind);
        self::assertSame('vendor/bin/agent-loop init install-assets --agent=opencode', $projection->nextAction);

        ob_start();
        try {
            $exit = (new InitHostStatusCommand($this->root, $probe))->run(['--format=json']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit, $output);

        try {
            $cli = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            self::fail('Host status did not return valid JSON: ' . $exception->getMessage() . "\n" . $output);
        }
        self::assertIsArray($cli);
        self::assertSame($projection->toArray(), $cli);
    }

    public function testAmbiguousRuntimeSelectionRemainsAnExplicitDecision(): void
    {
        $this->createExecutable('claude');
        $projection = (new RepositorySetupService(
            $this->root,
            new HostRuntimeProbe($this->binRoot, self::pathExt()),
        ))->overview();

        self::assertNull($projection->host);
        self::assertSame('ambiguous', $projection->selection);
        self::assertNull($projection->runtime);
        self::assertNull($projection->integration);
        self::assertSame('decision_required', $projection->nextActionKind);
        self::assertIsString($projection->nextAction);
        self::assertStringContainsString('--agent=<claude|opencode>', $projection->nextAction);
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
