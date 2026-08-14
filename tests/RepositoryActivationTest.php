<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Init\RepositoryActivation;

/**
 * @internal
 */
final class RepositoryActivationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-activation-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testConsumerRepositoryResolvesTheVendorBinary(): void
    {
        $this->writeComposerName('acme/app');

        self::assertSame('vendor/bin/agent-loop', (new RepositoryActivation($this->root))->cliPath());
    }

    public function testPackageCheckoutResolvesItsOwnBinaryBecauseComposerNeverLinksTheRootPackage(): void
    {
        $this->writeComposerName('voku/agent-loop');

        self::assertSame('bin/agent-loop', (new RepositoryActivation($this->root))->cliPath());
    }

    public function testMissingCliIsReportedAsTheFirstThingToFix(): void
    {
        $this->writeComposerName('acme/app');
        $activation = new RepositoryActivation($this->root);

        self::assertFalse($activation->cliAvailable());
        self::assertStringContainsString(
            '[WARN] CLI: vendor/bin/agent-loop is missing',
            $activation->cliCheck()->render(),
        );

        mkdir($this->root . '/vendor/bin', 0o775, true);
        file_put_contents($this->root . '/vendor/bin/agent-loop', "#!/usr/bin/env php\n");

        $activation = new RepositoryActivation($this->root);
        self::assertTrue($activation->cliAvailable());
        self::assertSame('[OK] CLI: vendor/bin/agent-loop', $activation->cliCheck()->render());
    }

    public function testHookDirectoryDefaultsToTheConsumerConvention(): void
    {
        $activation = new RepositoryActivation($this->root);

        self::assertSame('.githooks', $activation->gitHooksDirectory());
        self::assertSame(
            'vendor/bin/agent-loop init sync-githooks --hooks-dir=.githooks',
            $activation->syncGitHooksCommand(),
        );
    }

    public function testExistingPackageOwnedHookDirectoryIsMaintainedInsteadOfDuplicated(): void
    {
        $this->writeComposerName('voku/agent-loop');
        $this->writePackageHooks('githooks');
        file_put_contents($this->root . '/.gitmessage', "# template\n");

        $activation = new RepositoryActivation($this->root);

        self::assertSame('githooks', $activation->gitHooksDirectory());
        self::assertSame(
            'bin/agent-loop init sync-githooks --hooks-dir=githooks --commit-template=.gitmessage --adopt-existing',
            $activation->syncGitHooksCommand(),
        );
    }

    public function testAdoptExistingIsDroppedOnceTheHookDirectoryIsManaged(): void
    {
        $this->writePackageHooks('.githooks');
        file_put_contents(
            $this->root . '/.githooks/.agent-loop-manifest.json',
            json_encode(['version' => 1, 'kind' => 'githooks', 'agent' => 'git', 'entries' => []]),
        );

        self::assertSame(
            'vendor/bin/agent-loop init sync-githooks --hooks-dir=.githooks',
            (new RepositoryActivation($this->root))->syncGitHooksCommand(),
        );
    }

    public function testConfiguredHookPathPointingAtNothingIsNotActivation(): void
    {
        $runner = new ProcessRunner($this->root);
        if ($runner->run(['git', '--version'])['exit_code'] !== 0) {
            self::markTestSkipped('git is not available.');
        }

        $runner->mustRun(['git', 'init', '--quiet']);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{}\n");
        $runner->mustRun(['git', 'config', 'core.hooksPath', '.githooks']);

        $rendered = $this->renderedChecks(new RepositoryActivation($this->root));
        self::assertStringContainsString(
            '[WARN] Git hooks: core.hooksPath=.githooks points at a missing directory',
            $rendered,
        );

        $this->writePackageHooks('.githooks');

        self::assertStringContainsString(
            '[OK] Git hooks: core.hooksPath=.githooks',
            $this->renderedChecks(new RepositoryActivation($this->root)),
        );
    }

    public function testRepositoryWithoutGitHookPolicyReportsNoGitIntegration(): void
    {
        $runner = new ProcessRunner($this->root);
        if ($runner->run(['git', '--version'])['exit_code'] !== 0) {
            self::markTestSkipped('git is not available.');
        }

        $runner->mustRun(['git', 'init', '--quiet']);

        self::assertSame('', $this->renderedChecks(new RepositoryActivation($this->root)));
    }

    private function renderedChecks(RepositoryActivation $activation): string
    {
        $lines = [];
        foreach ($activation->localGitIntegrationChecks() as $check) {
            $lines[] = $check->render();
        }

        return implode("\n", $lines);
    }

    private function writeComposerName(string $name): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => $name], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    private function writePackageHooks(string $directory): void
    {
        mkdir($this->root . '/' . $directory . '/lib', 0o775, true);
        file_put_contents($this->root . '/' . $directory . '/lib/agent-loop-hooks.sh', "#!/usr/bin/env bash\n");
        file_put_contents($this->root . '/' . $directory . '/pre-commit', "#!/usr/bin/env bash\n");
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

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
