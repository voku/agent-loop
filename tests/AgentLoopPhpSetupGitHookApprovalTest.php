<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `init install-assets` registers Git hooks (`core.hooksPath`, `commit.template`)
 * whenever `.agent-loop/githooks.json` exists unless `--skip-git-config` is passed.
 * The PHP setup guidance requires a separate approval for Git hook registration,
 * so every `install-assets` command it tells a host to run must carry the flag.
 *
 * @internal
 */
final class AgentLoopPhpSetupGitHookApprovalTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function guidanceFiles(): iterable
    {
        yield 'skill' => [__DIR__ . '/../resources/skills/agent-loop-php-setup/SKILL.md'];
        yield 'onboarding guide' => [__DIR__ . '/../docs/reference/php-project-onboarding.md'];
    }

    #[DataProvider('guidanceFiles')]
    public function testEveryInstallAssetsCommandSkipsGitConfig(string $path): void
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        $matched = preg_match_all('/^vendor\/bin\/agent-loop init install-assets\b.*$/m', $contents, $matches);
        self::assertGreaterThan(0, $matched, 'Expected at least one install-assets command in ' . $path);

        foreach ($matches[0] as $command) {
            self::assertStringContainsString('--skip-git-config', $command, $path);
        }
    }

    #[DataProvider('guidanceFiles')]
    public function testGitHookRegistrationIsASeparateApprovedStep(string $path): void
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        self::assertStringContainsString('init sync-githooks', $contents, $path);
    }
}
