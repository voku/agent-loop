<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\PackageResources;
use voku\AgentLoop\GitHooks\GitHookConfig;

final class RepositoryGitHookPolicyTest extends TestCase
{
    public function testSelfDogfoodRepositoryDoesNotInstallNoOpGitHooks(): void
    {
        $root = dirname(__DIR__);
        $config = GitHookConfig::load($root);

        self::assertNotEmpty($config->checks, 'Tracked pre-commit hooks must have at least one real check.');
        self::assertSame('php -l', $config->checks[0]['command']);
        self::assertTrue($config->checks[0]['per_file']);
        self::assertFileExists($root . '/.gitmessage');
    }

    /**
     * The package's own hook sources are adopted in place in this repository, so
     * their tracked mode is what Git ends up running. `post-checkout` and
     * `post-merge` `exec` the refresh script directly: shipping it without the
     * execute bit turns every checkout into a "Permission denied" hook failure.
     */
    public function testExecutedHookSourcesAreShippedExecutable(): void
    {
        $root = dirname(__DIR__);
        foreach (['pre-commit', 'commit-msg', 'post-checkout', 'post-merge', 'agent-map-refresh.sh'] as $hook) {
            $path = $root . '/' . PackageResources::GIT_HOOKS . '/' . $hook;

            self::assertFileExists($path);
            self::assertTrue(is_executable($path), $hook . ' is executed by Git and must be executable.');
        }
    }
}
