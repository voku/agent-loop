<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\PackageResources;

/**
 * A Git transition that changes only composer.lock can change a PHPStan
 * semantic map even though its indexed PHP files are byte-identical.
 *
 * Exercise the actual shipped shell hook with two real Git revisions rather
 * than searching its source text for the right pathspec.
 */
final class AgentMapGitHookRefreshTest extends TestCase
{
    private string $root;

    private string $logPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-map-hook-' . bin2hex(random_bytes(6));
        $this->logPath = $this->root . '/hook-executions.log';
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/vendor/bin', 0o775, true);
        mkdir($this->root . '/.agent-loop/map', 0o775, true);
        mkdir($this->root . '/.githooks/lib', 0o775, true);

        $resources = dirname(__DIR__) . '/' . PackageResources::GIT_HOOKS;
        foreach (['agent-map-refresh.sh', 'lib/agent-loop-hooks.sh'] as $resource) {
            if (!copy($resources . '/' . $resource, $this->root . '/.githooks/' . $resource)) {
                throw new RuntimeException('Unable to stage real Git hook: ' . $resource);
            }
        }

        // This stub deliberately records the real shell command's arguments.
        // It is invoked by PHP through the *actual* host-mode Git hook, not by
        // a mocked shell function.
        file_put_contents($this->root . '/vendor/bin/agent-map', <<<'PHP'
<?php

declare(strict_types=1);

file_put_contents(
    (string) getenv('MAP_HOOK_TEST_LOG'),
    implode(' ', $argv) . "\n",
    FILE_APPEND,
);
PHP);
        file_put_contents($this->root . '/src/Example.php', "<?php\nclass Example {}\n");
        file_put_contents($this->root . '/composer.lock', '{"packages":[]}' . "\n");
        file_put_contents($this->root . '/README.md', "# Example\n");
        file_put_contents($this->root . '/.agent-loop/map/php-symbols.json', "{}\n");

        $this->command(['git', 'init', '--quiet']);
        $this->commit('initial fixture');
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testComposerLockOnlyTransitionTriggersRefresh(): void
    {
        $old = $this->revision();
        file_put_contents($this->root . '/composer.lock', '{"packages":[{"name":"example/new-version"}]}' . "\n");
        $this->commit('dependency update');

        self::assertSame(0, $this->runHook($old, $this->revision()));
        self::assertSame(1, $this->refreshCount());
    }

    public function testPhpChangeStillTriggersRefresh(): void
    {
        $old = $this->revision();
        file_put_contents($this->root . '/src/Example.php', "<?php\nclass Example { public function go(): void {} }\n");
        $this->commit('change PHP source');

        self::assertSame(0, $this->runHook($old, $this->revision()));
        self::assertSame(1, $this->refreshCount());
    }

    public function testUnrelatedOnlyTransitionDoesNotRefresh(): void
    {
        $old = $this->revision();
        file_put_contents($this->root . '/README.md', "# Updated docs\n");
        $this->commit('docs only');

        self::assertSame(0, $this->runHook($old, $this->revision()));
        self::assertSame(0, $this->refreshCount());
    }

    public function testUnchangedCommitDoesNotRefresh(): void
    {
        $rev = $this->revision();

        self::assertSame(0, $this->runHook($rev, $rev));
        self::assertSame(0, $this->refreshCount());
    }

    public function testMissingIndexDoesNotBuildEvenWhenLockChanges(): void
    {
        $old = $this->revision();
        file_put_contents($this->root . '/composer.lock', '{"packages":[{"name":"example/dependency"}]}' . "\n");
        $this->commit('dependency update');
        unlink($this->root . '/.agent-loop/map/php-symbols.json');

        self::assertSame(0, $this->runHook($old, $this->revision()));
        self::assertSame(0, $this->refreshCount());
    }

    public function testOptOutSkipsLockOnlyRefresh(): void
    {
        $old = $this->revision();
        file_put_contents($this->root . '/composer.lock', '{"packages":[{"name":"example/dependency"}]}' . "\n");
        $this->commit('dependency update');

        self::assertSame(0, $this->runHook($old, $this->revision(), ['AGENT_LOOP_SKIP_MAP_REFRESH' => '1']));
        self::assertSame(0, $this->refreshCount());
    }

    private function revision(): string
    {
        $revision = trim($this->command(['git', 'rev-parse', 'HEAD'])['stdout']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40}$/', $revision);

        return $revision;
    }

    private function commit(string $message): void
    {
        $this->command(['git', 'add', '-A']);
        $this->command([
            'git',
            '-c',
            'user.name=AgentMapHookTest',
            '-c',
            'user.email=agent-map-hook-test@example.invalid',
            'commit',
            '--quiet',
            '-m',
            $message,
        ]);
    }

    /** @param array<string, string> $environment */
    private function runHook(string $old, string $new, array $environment = []): int
    {
        $result = $this->command(
            ['bash', $this->root . '/.githooks/agent-map-refresh.sh', $old, $new],
            $environment,
        );

        return $result['exit'];
    }

    private function refreshCount(): int
    {
        if (!is_file($this->logPath)) {
            return 0;
        }

        $lines = file($this->logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        self::assertIsArray($lines);
        foreach ($lines as $line) {
            self::assertStringContainsString(' refresh ', $line);
            self::assertStringContainsString('--index=.agent-loop/map/php-symbols.json', $line);
        }

        return count($lines);
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $environment
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function command(array $argv, array $environment = []): array
    {
        $env = getenv();
        if (!is_array($env)) {
            $env = [];
        }
        $env['MAP_HOOK_TEST_LOG'] = $this->logPath;
        $env['AGENT_LOOP_CONTAINER_WORKDIR'] = '';
        $env['AGENT_LOOP_CONTAINER_IMAGE'] = '';
        $env['AGENT_LOOP_CONTAINER_SERVICE'] = '';
        $env['AGENT_LOOP_SKIP_MAP_REFRESH'] = '0';
        foreach ($environment as $key => $value) {
            $env[$key] = $value;
        }

        $process = proc_open(
            $argv,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $env,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch Git hook test command.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);
        self::assertSame(0, $exitCode, (string) $stderr . "\n" . (string) $stdout);

        return ['exit' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }
}
