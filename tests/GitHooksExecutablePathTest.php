<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;

final class GitHooksExecutablePathTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hook-bin-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        exec('git -C ' . escapeshellarg($this->root) . ' init --quiet 2>/dev/null');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testRootPackageBinaryIsUsedWhenComposerWrapperIsAbsent(): void
    {
        $binary = $this->root . '/bin/agent-loop';
        mkdir(dirname($binary), 0o775, true);
        file_put_contents($binary, "#!/usr/bin/env php\n");
        chmod($binary, 0o755);

        self::assertSame($binary, $this->resolvedBin());
    }

    public function testComposerWrapperIsPreferredWhenBothBinariesExist(): void
    {
        $packageBinary = $this->root . '/bin/agent-loop';
        $composerBinary = $this->root . '/vendor/bin/agent-loop';
        mkdir(dirname($packageBinary), 0o775, true);
        mkdir(dirname($composerBinary), 0o775, true);
        file_put_contents($packageBinary, "#!/usr/bin/env php\n");
        file_put_contents($composerBinary, "#!/usr/bin/env php\n");
        chmod($packageBinary, 0o755);
        chmod($composerBinary, 0o755);

        self::assertSame($composerBinary, $this->resolvedBin());
    }

    public function testExplicitEnvironmentOverrideRemainsAuthoritative(): void
    {
        self::assertSame('php -d memory_limit=4G tools/agent-loop.php', $this->resolvedBin([
            'AGENT_LOOP_BIN' => 'php -d memory_limit=4G tools/agent-loop.php',
        ]));
    }

    /** @param array<string, string> $environment */
    private function resolvedBin(array $environment = []): string
    {
        $helper = $this->root . '/agent-loop-hooks.sh';
        self::assertTrue(copy(dirname(__DIR__) . '/resources/githooks/lib/agent-loop-hooks.sh', $helper));
        $script = 'source ' . escapeshellarg($helper) . '; printf "%s" "${AGENT_LOOP_BIN:-}"';
        $processEnvironment = getenv();
        unset($processEnvironment['AGENT_LOOP_BIN']);
        $processEnvironment = array_merge($processEnvironment, $environment);
        $process = proc_open(
            ['bash', '-lc', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $processEnvironment,
        );
        self::assertIsResource($process);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), (string) $stderr);

        return trim((string) $stdout);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);

                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
