<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
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
}
