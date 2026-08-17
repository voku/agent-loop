<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;

final class GitHookMapRefreshSecurityTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hook-map-security-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/githooks/lib', 0o775, true);
        mkdir($this->root . '/vendor/bin', 0o775, true);
        mkdir($this->root . '/.agent-loop/map', 0o775, true);

        copy(dirname(__DIR__) . '/githooks/agent-map-refresh.sh', $this->root . '/githooks/agent-map-refresh.sh');
        copy(dirname(__DIR__) . '/githooks/lib/agent-loop-hooks.sh', $this->root . '/githooks/lib/agent-loop-hooks.sh');
        file_put_contents($this->root . '/.agent-loop/map/php-symbols.json', "{}\n");
        file_put_contents(
            $this->root . '/vendor/bin/agent-map',
            <<<'PHP'
<?php

declare(strict_types=1);

$capture = getenv('AGENT_MAP_CAPTURE');
if (!is_string($capture) || $capture === '') {
    exit(2);
}
file_put_contents($capture, json_encode($argv, JSON_THROW_ON_ERROR));
PHP,
        );

        $this->git(['init', '-b', 'main']);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCheckoutRefreshExplicitlySelectsStructuralBackend(): void
    {
        $capture = $this->root . '/captured-argv.json';
        $environment = getenv();
        self::assertIsArray($environment);
        $environment['AGENT_MAP_CAPTURE'] = $capture;

        $process = proc_open(
            ['bash', $this->root . '/githooks/agent-map-refresh.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            $environment,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start map refresh hook.');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        self::assertSame(0, $exit, trim((string) $stdout . "\n" . (string) $stderr));
        self::assertFileExists($capture);

        $argv = json_decode((string) file_get_contents($capture), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($argv);
        self::assertContains('refresh', $argv);
        self::assertContains('--backend=structural', $argv);
        self::assertSame(
            [],
            array_values(array_filter(
                $argv,
                static fn (mixed $argument): bool => is_string($argument) && str_starts_with($argument, '--phpstan-'),
            )),
            'The checkout trust transition must not inherit branch-controlled PHPStan execution policy.',
        );
    }

    /** @param list<string> $args */
    private function git(array $args): void
    {
        $process = proc_open(['git', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start git.');
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException(trim((string) $stderr) ?: 'git failed');
        }
    }

    private function removeDirectory(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeDirectory($path . '/' . $entry);
        }
        rmdir($path);
    }
}
