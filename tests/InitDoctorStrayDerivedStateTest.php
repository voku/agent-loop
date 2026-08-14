<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitDoctorCommand;

/**
 * agent-map writes its PHPStan result cache to a hardcoded `.agent-map/`,
 * outside the workflow state root and with no option to move it.
 *
 * A real anti-xss consumer replay gained 5.3 MB of untracked cache there that
 * its `.gitattributes` export rules did not cover, so it could have been
 * committed and shipped in a Composer dist. The doctor names it; it does not
 * delete another package's files, and it stays quiet when there is nothing to
 * say.
 *
 * @internal
 */
final class InitDoctorStrayDerivedStateTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (self::git('--version') === null) {
            self::markTestSkipped('git is not available.');
        }

        $this->root = sys_get_temp_dir() . '/agent-loop-stray-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
        self::git('-C', $this->root, 'init', '--quiet', '--initial-branch=main');
        file_put_contents($this->root . '/composer.json', '{"name":"acme/consumer"}');
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testAnUnignoredMapCacheIsReported(): void
    {
        mkdir($this->root . '/.agent-map/phpstan-cache', 0o775, true);

        $output = $this->doctor();

        self::assertStringContainsString('[WARN] Derived state: .agent-map/ is not ignored by Git', $output);
        self::assertStringContainsString('.gitattributes', $output, 'A library also needs the export boundary named.');
    }

    public function testAnIgnoredMapCacheIsNotReported(): void
    {
        mkdir($this->root . '/.agent-map/phpstan-cache', 0o775, true);
        file_put_contents($this->root . '/.gitignore', "/.agent-map/\n");

        self::assertStringNotContainsString('Derived state:', $this->doctor());
    }

    public function testNothingIsReportedWhenTheCacheDoesNotExist(): void
    {
        self::assertStringNotContainsString('Derived state:', $this->doctor());
    }

    private function doctor(): string
    {
        ob_start();
        (new InitDoctorCommand($this->root))->run([]);

        return (string) ob_get_clean();
    }

    private static function git(string ...$arguments): ?string
    {
        $command = ['git'];
        foreach ($arguments as $argument) {
            $command[] = $argument;
        }

        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($stdout) ? trim($stdout) : null;
    }

    private function rm(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->rm($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
