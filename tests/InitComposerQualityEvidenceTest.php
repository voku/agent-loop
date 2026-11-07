<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitComposerQualityEvidence;
use voku\AgentLoop\Init\InitRecommendCommand;

/** @internal */
final class InitComposerQualityEvidenceTest extends TestCase
{
    public function testOnlyDeclaredQualityScriptNamesAreProposed(): void
    {
        $scripts = (new InitComposerQualityEvidence())->scriptNames([
            'scripts' => [
                'test:unit' => '@php vendor/bin/phpunit',
                'ci' => ['@php -v', '@php -r "exit(0);"'],
                'phpstan' => 'vendor/bin/phpstan',
                'cs' => '@php vendor/bin/php-cs-fixer check',
                'post-install-cmd' => 'unexpected lifecycle hook',
                'not-quality' => 'ignored',
                'ci && echo unsafe' => 'invalid shell-like script name',
                'lint' => 123,
            ],
        ]);

        self::assertSame(['ci', 'cs', 'phpstan', 'test:unit'], $scripts);
    }

    public function testMissingOrInvalidScriptsCannotInventValidation(): void
    {
        $evidence = new InitComposerQualityEvidence();

        self::assertSame([], $evidence->scriptNames([]));
        self::assertSame([], $evidence->scriptNames(['scripts' => 'composer test']));
        self::assertSame([], $evidence->scriptNames(['scripts' => ['install' => 'true']]));
    }

    public function testReadOnlyInspectionReportsExistingScriptsEvenWithPhpstanScope(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-composer-quality-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($root . '/src', 0o775, true));
        file_put_contents($root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n");
        file_put_contents($root . '/composer.json', json_encode([
            'name' => 'acme/quality-consumer',
            'scripts' => [
                'ci' => ['@php vendor/bin/phpunit', '@php vendor/bin/phpstan'],
                'test:unit' => '@php vendor/bin/phpunit --testsuite unit',
                'post-install-cmd' => 'file_put_contents("PWNED", "bad")',
            ],
        ], JSON_THROW_ON_ERROR));

        try {
            $report = (new InitRecommendCommand($root))->inspect();

            self::assertSame(['src'], $report['map']['paths']);
            self::assertSame('composer.json', $report['quality']['source']);
            self::assertSame(['ci', 'test:unit'], $report['quality']['composer_scripts']);
            self::assertSame('Composer script names are declarations, not verified safe commands. Inspect script bodies and CI before executing them; never run scripts during discovery.', $report['quality']['note']);
            self::assertFileDoesNotExist($root . '/PWNED');
            self::assertSame([], $report['warnings']);
        } finally {
            unlink($root . '/composer.json');
            unlink($root . '/phpstan.neon');
            rmdir($root . '/src');
            rmdir($root);
        }
    }
}
