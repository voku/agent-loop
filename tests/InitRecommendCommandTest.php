<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\InitRecommendCommand;

/** @internal */
final class InitRecommendCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-recommend-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testNoToolConfigurationDoesNotInventProjectScopeOrLanguage(): void
    {
        $command = new InitRecommendCommand($this->root);
        $report = $command->inspect();

        self::assertSame(1, $report['schema_version']);
        self::assertSame([], $report['map']['paths']);
        self::assertNull($report['map']['command']);
        self::assertSame('en', $report['prompting']['language']);
        self::assertSame('default', $report['prompting']['source']);
        self::assertSame(3, $report['review']['human_attention_every_changes_required']);
        self::assertSame(['vendor/bin/agent-loop init sync-tools --dry-run'], $report['next_actions']);

        ob_start();
        try {
            $exit = $command->run(['--format=json']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit);
        self::assertSame($report, json_decode($output, true, 512, JSON_THROW_ON_ERROR));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testPhpstanPathsAreProposalsButExcludesAreNotCopiedToSlop(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents(
            $this->root . '/phpstan.neon.dist',
            <<<'NEON'
includes:
  - vendor/project/extension.neon
parameters:
  level: 8
  paths:
    - src
    - tests
    - ../external
    - vendor
  excludePaths:
    - tests/fixtures/*
NEON,
        );
        file_put_contents(
            $this->root . '/.php-cs-fixer.dist.php',
            <<<'PHP'
<?php
file_put_contents(__DIR__ . '/pwned', 'unexpected execution');
return (new PhpCsFixer\Config())->setFinder(PhpCsFixer\Finder::create()->exclude('fixtures'));
PHP,
        );

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src', 'tests'], $report['map']['paths']);
        self::assertSame('phpstan.neon.dist', $report['map']['source']);
        self::assertSame('vendor/bin/agent-loop map build --paths=src,tests', $report['map']['command']);
        self::assertSame(['tests/fixtures/*'], $report['exclusions']['phpstan']);
        self::assertSame([], $report['exclusions']['slop']);
        self::assertSame('.php-cs-fixer.dist.php', $report['sources']['php_cs_fixer']);
        self::assertFileDoesNotExist($this->root . '/pwned');
        self::assertFileDoesNotExist($this->root . '/slop-scan.config.json');
    }

    public function testExistingUserPreferencesAndSlopRulesAreReportedNotOverwritten(): void
    {
        mkdir($this->root . '/.agent-loop', 0o775, true);
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', '{"version":1,"prompting":{"language":"de-DE"}}');
        file_put_contents($this->root . '/slop-scan.config.json', '{"ignores":["**/vendor/**","**/generated/**"],"rules":{"php.magic-numbers":{"enabled":false}}}');
        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame('de-de', $report['prompting']['language']);
        self::assertSame('.agent-loop/init.json', $report['prompting']['source']);
        self::assertSame(['**/vendor/**', '**/generated/**'], $report['exclusions']['slop']);
        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame('directory-presence', $report['map']['source']);
        self::assertSame([], $report['warnings']);
    }

    public function testComposerPsr4ScopeIsFallbackWhenPhpstanScopeCannotBeReadStatically(): void
    {
        mkdir($this->root . '/app', 0o775, true);
        mkdir($this->root . '/lib', 0o775, true);
        file_put_contents($this->root . '/phpstan.neon', "includes:\n  - other.neon\nparameters:\n  paths: %dynamicPath%\n");
        file_put_contents($this->root . '/composer.json', json_encode([
            'autoload' => ['psr-4' => ['Acme\\' => ['app/', 'lib/'], 'Outside\\' => '../outside']],
        ], JSON_THROW_ON_ERROR));

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['app', 'lib'], $report['map']['paths']);
        self::assertSame('composer.json', $report['map']['source']);
        self::assertSame('phpstan.neon', $report['sources']['phpstan']);
    }

    public function testLiteralRootScopeAndNestedExcludePathsStaySeparate(): void
    {
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths:\n    - .\n  excludePaths:\n    analyse:\n      - tests/fixtures/*\n");

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['.'], $report['map']['paths']);
        self::assertSame('vendor/bin/agent-loop map build --paths=.', $report['map']['command']);
        self::assertSame(['tests/fixtures/*'], $report['exclusions']['phpstan']);
        self::assertSame([], $report['exclusions']['slop']);
    }

    public function testNestedPhpstanPathsNeverBecomeAnImplicitMapScope(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/app', 0o775, true);
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths:\n    unexpected:\n      - src\n");
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"app/"}}}');

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['app'], $report['map']['paths']);
        self::assertSame('composer.json', $report['map']['source']);
        self::assertSame('vendor/bin/agent-loop map build --paths=app', $report['map']['command']);
    }

    public function testNestedUnrelatedPhpstanPathKeyCannotOverrideDirectPaths(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/app', 0o775, true);
        file_put_contents(
            $this->root . '/phpstan.neon',
            "parameters:\n  customSettings:\n    paths:\n      - src\n  paths:\n    - app\n",
        );

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['app'], $report['map']['paths']);
        self::assertSame('phpstan.neon', $report['map']['source']);
        self::assertSame('agent-loop map build --paths=app', $report['map']['command']);
    }

    public function testNestedUnrelatedPhpstanPathKeyCannotBecomeMapScope(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/app', 0o775, true);
        file_put_contents(
            $this->root . '/phpstan.neon',
            "parameters:\n  customSettings:\n    paths:\n      - src\n",
        );
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR),
        );

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['app'], $report['map']['paths']);
        self::assertSame('composer.json', $report['map']['source']);
        self::assertSame('agent-loop map build --paths=app', $report['map']['command']);
    }

    public function testNestedPhpstanExcludePathsCannotOverrideDirectExclusions(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/phpstan.neon',
            "parameters:\n  customSettings:\n    excludePaths:\n      - hidden/*\n  paths:\n    - src\n  excludePaths:\n    - tests/fixtures/*\n",
        );

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame(['tests/fixtures/*'], $report['exclusions']['phpstan']);
        self::assertSame([], $report['exclusions']['slop']);
    }

    public function testMixedPhpstanListAndNestedMappingIsNotPartiallyAccepted(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n    unexpected:\n      - tests\n");

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src', 'tests'], $report['map']['paths']);
        self::assertSame('directory-presence', $report['map']['source']);
    }

    public function testOutOfRootPhpstanSymlinkFailsClosed(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'init-neon-');
        self::assertIsString($outside);
        file_put_contents($outside, "parameters:\n  paths:\n    - .\n");
        self::assertTrue(symlink($outside, $this->root . '/phpstan.neon'));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Symlinked project config');
            (new InitRecommendCommand($this->root))->inspect();
        } finally {
            unlink($outside);
        }
    }

    public function testSymlinkedConfigParentCannotEscapeTheProject(): void
    {
        $outside = sys_get_temp_dir() . '/init-outside-' . bin2hex(random_bytes(6));
        mkdir($outside, 0o775, true);
        file_put_contents($outside . '/init.json', '{"version":1,"prompting":{"language":"de"}}');
        self::assertTrue(symlink($outside, $this->root . '/.agent-loop'));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('repository file');
            (new InitRecommendCommand($this->root))->inspect();
        } finally {
            unlink($this->root . '/.agent-loop');
            unlink($outside . '/init.json');
            rmdir($outside);
        }
    }

    public function testOversizedAgentLoopConfigFailsBeforeLoaderReadsIt(): void
    {
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', str_repeat(' ', 1048577));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');
        (new InitRecommendCommand($this->root))->inspect();
    }

    public function testAbsolutePhpstanScopeIsNeverReinterpretedAsRelative(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths:\n    - /src\n");

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame('directory-presence', $report['map']['source']);
    }

    public function testAbsoluteComposerScopeIsNeverReinterpretedAsRelative(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/composer.json', '{"autoload":{"psr-4":{"App\\\\":"/src"}}}');

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame('directory-presence', $report['map']['source']);
    }

    public function testRealPhpstanWorkingDirectoryPlaceholderKeepsSourceProvenance(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/phpstan.neon',
            "parameters:\n    level: 8\n    paths:\n        - %currentWorkingDirectory%/src/\n",
        );

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame('phpstan.neon', $report['map']['source']);
        self::assertSame('vendor/bin/agent-loop map build --paths=src', $report['map']['command']);
        self::assertSame([], $report['warnings']);
    }

    public function testWorkingDirectoryPlaceholderCannotTraverseOutsideProject(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/phpstan.neon',
            "parameters:\n  paths:\n    - %currentWorkingDirectory%/../outside\n",
        );

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame('directory-presence', $report['map']['source']);
    }

    public function testQuotedFourSpacePhpstanScopeAndNestedExcludesAreReadWithoutExecution(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/tests', 0o775, true);
        file_put_contents($this->root . '/phpstan.neon', <<<'NEON'
parameters:
    paths:
        - "src"
        - 'tests'
    excludePaths:
      analyse:
        - "tests/fixtures/*"
NEON);

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src', 'tests'], $report['map']['paths']);
        self::assertSame('phpstan.neon', $report['map']['source']);
        self::assertSame(['tests/fixtures/*'], $report['exclusions']['phpstan']);
        self::assertSame([], $report['warnings']);
    }

    public function testTabIndentedPhpstanListsEmitWarningsInsteadOfSilentlyFallingBack(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents(
            $this->root . '/phpstan.neon',
            "parameters:\n\tpaths:\n\t\t- src\n\texcludePaths:\n\t\t- tests/fixtures/*\n",
        );

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame('directory-presence', $report['map']['source']);
        self::assertSame([], $report['exclusions']['phpstan']);
        self::assertCount(2, $report['warnings']);
        self::assertStringContainsString('parameters.paths', $report['warnings'][0]);
        self::assertStringContainsString('parameters.excludePaths', $report['warnings'][1]);
    }

    public function testUnsupportedPhpstanEntriesEmitWarningsInsteadOfSilentExclusions(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths: %dynamicPaths%\n  excludePaths:\n    - %dynamicExclude%\n");

        $report = (new InitRecommendCommand($this->root))->inspect();

        self::assertSame(['src'], $report['map']['paths']);
        self::assertSame('directory-presence', $report['map']['source']);
        self::assertSame([], $report['exclusions']['phpstan']);
        self::assertCount(2, $report['warnings']);
        self::assertStringContainsString('parameters.paths', $report['warnings'][0]);
        self::assertStringContainsString('parameters.excludePaths', $report['warnings'][1]);
    }

    public function testMalformedSlopConfigurationFailsClosed(): void
    {
        file_put_contents($this->root . '/slop-scan.config.json', '{ broken');

        $this->expectException(\JsonException::class);
        (new InitRecommendCommand($this->root))->inspect();
    }

    public function testLargeConfigFailsClosedWithoutExecutingIt(): void
    {
        file_put_contents($this->root . '/phpstan.neon', str_repeat(' ', 1048577));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');
        (new InitRecommendCommand($this->root))->inspect();
    }

    public function testUnknownArgumentsDoNotCreateSetupFiles(): void
    {
        self::assertSame(1, (new InitRecommendCommand($this->root))->run(['--apply']));
        self::assertFileDoesNotExist($this->root . '/.agent-loop/init.json');
    }
}
