<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitPlanCommand;

/** @internal */
final class InitPlanCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-plan-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testEmptyProjectProducesNoInventedChangesOrCommands(): void
    {
        $command = new InitPlanCommand($this->root);
        $plan = $command->inspect();

        self::assertSame(1, $plan['schema_version']);
        self::assertSame('read_only', $plan['mode']);
        self::assertSame([], $plan['proposals']);
        self::assertSame([], $plan['file_changes']);
        self::assertSame(64, strlen($plan['evidence_sha256']));
        self::assertSame([], $plan['warnings']);

        ob_start();
        try {
            $exitCode = $command->run(['--format=json']);
            $json = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exitCode);
        self::assertSame($plan, json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testLiteralScopeAndDeclaredScriptsRemainSeparateUnapprovedCandidates(): void
    {
        mkdir($this->root . '/src', 0o775, true);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n  excludePaths:\n    - src/generated/*\n");
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'test/consumer',
            'scripts' => [
                'ci' => '@php -r "file_put_contents(\'PWNED\', \'bad\');"',
                'test' => 'vendor/bin/phpunit',
                'post-install-cmd' => 'echo ignored',
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/slop-scan.config.json', '{"ignores":["vendor/**"],"custom":"leave-me"}');
        file_put_contents($this->root . '/.agent-loop/init.json', '{"version":1,"prompting":{"language":"de-de"},"custom":"leave-me"}');

        $paths = [
            'phpstan.neon',
            'composer.json',
            'slop-scan.config.json',
            '.agent-loop/init.json',
        ];
        $before = [];
        foreach ($paths as $path) {
            $before[$path] = file_get_contents($this->root . '/' . $path);
        }

        $command = new InitPlanCommand($this->root);
        $plan = $command->inspect();

        self::assertSame([], $plan['file_changes']);
        self::assertSame(['map.scope', 'composer.script.ci', 'composer.script.test'], array_column($plan['proposals'], 'id'));
        self::assertSame('phpstan.neon', $plan['proposals'][0]['source']);
        self::assertSame('literal_config', $plan['proposals'][0]['confidence']);
        self::assertSame('vendor/bin/agent-loop map build --paths=src', $plan['proposals'][0]['proposed']);
        self::assertSame('not_authorized', $plan['proposals'][0]['execution']);
        self::assertContains('Run the command only after explicit approval.', $plan['proposals'][0]['prerequisites']);
        self::assertSame('unverified_script_name', $plan['proposals'][1]['confidence']);
        self::assertSame('ci', $plan['proposals'][1]['proposed']);
        self::assertSame('composer.json', $plan['proposals'][1]['source']);
        self::assertSame($plan, $command->inspect());

        foreach ($paths as $path) {
            self::assertSame($before[$path], file_get_contents($this->root . '/' . $path));
        }
        self::assertFileDoesNotExist($this->root . '/PWNED');
    }

    public function testDevOnlyComposerDeclarationRetainsOwnerAndConfidence(): void
    {
        mkdir($this->root . '/spec', 0o775, true);
        file_put_contents($this->root . '/composer.json', json_encode([
            'autoload-dev' => ['psr-4' => ['Project\\Tests\\' => 'spec/']],
        ], JSON_THROW_ON_ERROR));

        $plan = (new InitPlanCommand($this->root))->inspect();

        self::assertSame(['map.scope'], array_column($plan['proposals'], 'id'));
        self::assertSame('agent-map', $plan['proposals'][0]['owner']);
        self::assertSame('composer.json:autoload-dev', $plan['proposals'][0]['source']);
        self::assertSame('declared_config', $plan['proposals'][0]['confidence']);
        self::assertSame('vendor/bin/agent-loop map build --paths=spec', $plan['proposals'][0]['proposed']);
        self::assertSame('not_authorized', $plan['proposals'][0]['execution']);
        self::assertSame([], $plan['file_changes']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testInvalidOptionsFailWithoutCreatingState(): void
    {
        $command = new InitPlanCommand($this->root);

        self::assertSame(1, $command->run(['--apply']));
        self::assertSame(1, $command->run(['--format=php']));
        self::assertFileDoesNotExist($this->root . '/.agent-loop/init.json');
    }

    public function testExplicitLanguageProducesOnlyReviewDiffWithoutConfigMutation(): void
    {
        mkdir($this->root . '/.agent-loop', 0o775, true);
        $config = '{"version":1,"prompting":{"language":"de-DE"},"custom":{"preserve":true}}';
        file_put_contents($this->root . '/.agent-loop/init.json', $config);

        $command = new InitPlanCommand($this->root);
        self::assertSame([], $command->inspect()['review_diffs']);
        self::assertSame([], $command->inspect('de-de')['review_diffs']);

        $plan = $command->inspect('fr-FR');
        self::assertSame([], $plan['file_changes']);
        self::assertCount(1, $plan['review_diffs']);
        self::assertSame('/prompting/language', $plan['review_diffs'][0]['pointer']);
        self::assertSame('de-de', $plan['review_diffs'][0]['before']);
        self::assertSame('fr-fr', $plan['review_diffs'][0]['after']);
        self::assertSame('not_authorized', $plan['review_diffs'][0]['execution']);
        self::assertSame($config, file_get_contents($this->root . '/.agent-loop/init.json'));
    }

    public function testDefaultLanguageIsNotMistakenForAConfiguredPreference(): void
    {
        $plan = (new InitPlanCommand($this->root))->inspect('de-DE');

        self::assertSame([], $plan['file_changes']);
        self::assertSame(null, $plan['review_diffs'][0]['before']);
        self::assertSame('default', $plan['review_diffs'][0]['before_source']);
        self::assertSame('de-de', $plan['review_diffs'][0]['after']);
    }

    public function testEvidenceDigestBindsNormalizedRequestedLanguageWithoutChangingLegacyDigest(): void
    {
        $command = new InitPlanCommand($this->root);
        $legacy = $command->inspect();

        self::assertSame($legacy['evidence_sha256'], $command->inspect()['evidence_sha256']);

        $fr = $command->inspect('fr-FR');
        self::assertSame($fr['evidence_sha256'], $command->inspect('FR-fr')['evidence_sha256']);
        self::assertNotSame($legacy['evidence_sha256'], $fr['evidence_sha256']);
        self::assertNotSame($fr['evidence_sha256'], $command->inspect('ja')['evidence_sha256']);
        self::assertSame([], $fr['file_changes']);
    }

    public function testMalformedExistingConfigBlocksLanguageDiff(): void
    {
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/init.json', '{"prompting":{"language":42}}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot propose a language change');
        (new InitPlanCommand($this->root))->inspect('de-DE');
    }

    public function testLanguageArgumentRejectsInvalidAndDuplicateValuesWithoutWrites(): void
    {
        $command = new InitPlanCommand($this->root);

        foreach ([
            ['--language='],
            ['--language=../escape'],
            ['--language=de-DE', '--language=fr-FR'],
            ['--format=json', '--format=json'],
            ['--format=xml'],
        ] as $tokens) {
            self::assertSame(1, $command->run($tokens));
        }

        self::assertFileDoesNotExist($this->root . '/.agent-loop/init.json');
    }
}
