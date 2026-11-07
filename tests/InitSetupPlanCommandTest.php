<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitSetupPlanCommand;

/** @internal */
final class InitSetupPlanCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-plan-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0o775, true));
    }

    protected function tearDown(): void
    {
        if (is_file($this->root . '/.agent-loop/init.json')) {
            unlink($this->root . '/.agent-loop/init.json');
            rmdir($this->root . '/.agent-loop');
        }
        foreach (['phpstan.neon', 'composer.json', 'slop-scan.config.json', 'PWNED'] as $file) {
            if (is_file($this->root . '/' . $file)) {
                unlink($this->root . '/' . $file);
            }
        }
        if (is_dir($this->root . '/src')) {
            rmdir($this->root . '/src');
        }
        rmdir($this->root);
    }

    public function testNoConfigProducesNoGuessedChangesAndNoWrites(): void
    {
        $command = new InitSetupPlanCommand($this->root);
        $plan = $command->inspect();

        self::assertSame(1, $plan['schema_version']);
        self::assertSame([], $plan['candidates']);
        self::assertSame([], $plan['quality_script_names']);
        self::assertSame([], $plan['warnings']);
        self::assertSame([], $plan['preserved'][0]['values']);
        self::assertSame(3, $plan['preserved'][1]['value']);
        self::assertFalse($plan['authorization']['writes']);
        self::assertFalse($plan['authorization']['apply_supported']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testProposedMapCommandRemainsReviewableWhileSlopExcludesStaySeparate(): void
    {
        self::assertTrue(mkdir($this->root . '/src'));
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n  excludePaths:\n    - tests/fixtures/*\n");
        file_put_contents($this->root . '/slop-scan.config.json', json_encode(['ignores' => ['**/vendor/**']], JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/composer.json', json_encode([
            'scripts' => ['test' => 'php vendor/bin/phpunit', 'post-install-cmd' => 'touch PWNED'],
        ], JSON_THROW_ON_ERROR));

        $plan = (new InitSetupPlanCommand($this->root))->inspect();

        self::assertCount(1, $plan['candidates']);
        self::assertSame('map.build', $plan['candidates'][0]['id']);
        self::assertSame('agent-map', $plan['candidates'][0]['owner']);
        self::assertSame('phpstan.neon', $plan['candidates'][0]['source']);
        self::assertSame('source-backed', $plan['candidates'][0]['confidence']);
        self::assertSame('unknown', $plan['candidates'][0]['before']);
        self::assertSame(['operation' => 'run-after-approval', 'paths' => ['src']], $plan['candidates'][0]['diff']);
        self::assertSame(['**/vendor/**'], $plan['preserved'][0]['values']);
        self::assertSame(['test'], $plan['quality_script_names']);
        self::assertFileDoesNotExist($this->root . '/PWNED');
    }

    public function testExplicitLanguageProducesJsonMemberDiffWithoutOverwritingExistingSettings(): void
    {
        self::assertTrue(mkdir($this->root . '/.agent-loop'));
        $before = "{\n  \"version\": 1,\n  \"prompting\": {\"language\": \"en\", \"blindspots\": {\"tone\": \"direct\"}},\n  \"custom\": {\"retained\": true}\n}\n";
        file_put_contents($this->root . '/.agent-loop/init.json', $before);

        $plan = (new InitSetupPlanCommand($this->root))->inspect('de-DE');

        self::assertCount(1, $plan['candidates']);
        self::assertSame('prompting.language', $plan['candidates'][0]['id']);
        self::assertSame('en', $plan['candidates'][0]['before']);
        self::assertSame('de-de', $plan['candidates'][0]['after']);
        self::assertSame(['operation' => 'replace', 'pointer' => '/prompting/language', 'value' => 'de-de'], $plan['candidates'][0]['diff']);
        self::assertSame($before, file_get_contents($this->root . '/.agent-loop/init.json'));
        self::assertSame([], (new InitSetupPlanCommand($this->root))->inspect('en')['candidates']);
    }

    public function testDefaultLanguageIsNotInventedAsAnExistingUserPreference(): void
    {
        $plan = (new InitSetupPlanCommand($this->root))->inspect('fr');
        self::assertSame('prompting.language', $plan['candidates'][0]['id']);
        self::assertNull($plan['candidates'][0]['before']);
        self::assertSame('add', $plan['candidates'][0]['diff']['operation']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testUnsupportedFlagsAndMaliciousLanguageAreRejectedWithoutMutation(): void
    {
        $command = new InitSetupPlanCommand($this->root);
        self::assertSame(1, $command->run(['--apply']));
        self::assertSame(1, $command->run(['--language=../../etc/passwd']));
        self::assertSame(1, $command->run(['--language=de', '--language=fr']));
        self::assertSame(1, $command->run(['--format=yaml']));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testJsonCommandHasStableReadOnlyShape(): void
    {
        $command = new InitSetupPlanCommand($this->root);
        ob_start();
        try {
            $exit = $command->run(['--format=json', '--language=de']);
            $content = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame($command->inspect('de'), json_decode($content, true, 512, JSON_THROW_ON_ERROR));
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }
}
