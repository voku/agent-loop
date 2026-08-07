<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitSyncGitHooksCommand;

/**
 * @internal
 */
final class InitSyncGitHooksCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-githooks-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        exec('git -C ' . escapeshellarg($this->root) . ' init --quiet 2>/dev/null');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testInstallsHooksAndPointsGitAtThem(): void
    {
        file_put_contents($this->root . '/.gitmessage', "type: subject\n");

        $result = $this->runGitHooksSync(['--commit-template=.gitmessage', '--container-service=php', '--container-image=demo-php', '--container-workdir=/var/www/html', '--container-user=www-data']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.githooks/post-merge');
        self::assertFileExists($this->root . '/.githooks/post-checkout');
        self::assertFileExists($this->root . '/.githooks/agent-map-refresh.sh');
        self::assertFileExists($this->root . '/.githooks/lib/agent-loop-hooks.sh');
        self::assertTrue(is_executable($this->root . '/.githooks/post-merge'));

        $environment = (string) file_get_contents($this->root . '/.githooks/lib/agent-loop-hooks.env');
        self::assertStringContainsString("AGENT_LOOP_CONTAINER_IMAGE='demo-php'", $environment);
        self::assertStringContainsString("AGENT_LOOP_CONTAINER_WORKDIR='/var/www/html'", $environment);
        self::assertStringNotContainsString('AGENT_LOOP_MAP_INDEX', $environment);

        self::assertSame('.githooks', $this->gitConfig('core.hooksPath'));
        self::assertSame('.gitmessage', $this->gitConfig('commit.template'));
    }

    public function testInstalledHooksPassShellSyntaxCheck(): void
    {
        self::assertSame(0, $this->runGitHooksSync([])['exit']);

        foreach (['post-merge', 'post-checkout', 'pre-commit', 'commit-msg', 'agent-map-refresh.sh', 'lib/agent-loop-hooks.sh'] as $hook) {
            exec('bash -n ' . escapeshellarg($this->root . '/.githooks/' . $hook) . ' 2>&1', $output, $exitCode);
            self::assertSame(0, $exitCode, $hook . ': ' . implode("\n", $output));
        }
    }

    public function testHostOwnedHooksInTheSameDirectoryAreLeftAlone(): void
    {
        mkdir($this->root . '/.githooks', 0o775, true);
        file_put_contents($this->root . '/.githooks/prepare-commit-msg', "#!/usr/bin/env bash\necho project specific\n");

        self::assertSame(0, $this->runGitHooksSync([])['exit']);

        self::assertStringContainsString('project specific', (string) file_get_contents($this->root . '/.githooks/prepare-commit-msg'));

        $manifest = json_decode((string) file_get_contents($this->root . '/.githooks/.agent-loop-manifest.json'), true);
        self::assertSame('githooks', $manifest['kind']);
        self::assertNotContains('prepare-commit-msg', $manifest['entries']);
        self::assertContains('pre-commit', $manifest['entries']);
        self::assertContains('commit-msg', $manifest['entries']);
    }

    public function testUnmanagedHookIsRefusedUntilForced(): void
    {
        mkdir($this->root . '/.githooks', 0o775, true);
        file_put_contents($this->root . '/.githooks/post-merge', "#!/usr/bin/env bash\necho hand-written\n");

        $blocked = $this->runGitHooksSync([]);
        self::assertSame(1, $blocked['exit']);
        self::assertStringContainsString('unmanaged target already exists', $blocked['output']);
        self::assertStringContainsString('hand-written', (string) file_get_contents($this->root . '/.githooks/post-merge'));

        $forced = $this->runGitHooksSync(['--force']);
        self::assertSame(0, $forced['exit'], $forced['output']);
        self::assertStringNotContainsString('hand-written', (string) file_get_contents($this->root . '/.githooks/post-merge'));
    }

    public function testMissingCommitTemplateFailsBeforeGitIsTouched(): void
    {
        $result = $this->runGitHooksSync(['--commit-template=.gitmessage']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('commit template not found', $result['output']);
        self::assertSame('', $this->gitConfig('commit.template'));
    }

    public function testDryRunWritesNothing(): void
    {
        $result = $this->runGitHooksSync(['--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync githooks: install post-merge', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.githooks/post-merge');
        self::assertSame('', $this->gitConfig('core.hooksPath'));
    }

    public function testSkipGitConfigLeavesRepositoryConfigurationUntouched(): void
    {
        $result = $this->runGitHooksSync(['--skip-git-config']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.githooks/post-merge');
        self::assertSame('', $this->gitConfig('core.hooksPath'));
    }

    public function testCustomHooksDirectoryIsUsedForFilesAndGitConfig(): void
    {
        $result = $this->runGitHooksSync(['--hooks-dir=tools/githooks']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/tools/githooks/post-merge');
        self::assertSame('tools/githooks', $this->gitConfig('core.hooksPath'));
    }

    public function testHooksDirectoryOutsideTheRepositoryIsRejected(): void
    {
        // The rejection is an argument error, so it goes to STDERR like every other
        // argument error in this CLI; what matters here is that nothing was written.
        $result = $this->runGitHooksSync(['--hooks-dir=../elsewhere']);

        self::assertSame(1, $result['exit']);
        self::assertDirectoryDoesNotExist(dirname($this->root) . '/elsewhere');
        self::assertSame('', $this->gitConfig('core.hooksPath'));
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runGitHooksSync(array $tokens): array
    {
        ob_start();
        $exit = (new InitSyncGitHooksCommand($this->root))->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private function gitConfig(string $key): string
    {
        $output = [];
        exec('git -C ' . escapeshellarg($this->root) . ' config --get ' . escapeshellarg($key) . ' 2>/dev/null', $output);

        return trim(implode('', $output));
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
