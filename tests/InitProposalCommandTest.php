<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitProposalCommand;

/** @internal */
final class InitProposalCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-plan-' . bin2hex(random_bytes(8));
        if (!mkdir($this->root, 0o700)) {
            self::fail('Could not create disposable project root.');
        }
    }

    protected function tearDown(): void
    {
        foreach (['.agent-loop/init.json', 'composer.json', 'phpstan.neon', 'EXECUTED'] as $path) {
            $file = $this->root . '/' . $path;
            if (is_file($file)) {
                unlink($file);
            }
        }

        if (is_dir($this->root . '/.agent-loop')) {
            rmdir($this->root . '/.agent-loop');
        }
        if (is_dir($this->root . '/src')) {
            rmdir($this->root . '/src');
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
    }

    public function testNoEvidenceDoesNotInventChangesOrProjectPaths(): void
    {
        $command = new InitProposalCommand($this->root);
        $report = $command->inspect();

        self::assertSame(1, $report['schema_version']);
        self::assertSame('proposal_only', $report['state']);
        self::assertSame([], $report['candidates']);
        self::assertSame([], $report['changes']);
        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');

        ob_start();
        try {
            $exit = $command->run(['--format=json']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame($report, json_decode($output, true, 512, JSON_THROW_ON_ERROR));
    }

    public function testLanguageDiffIsExplicitAndNeverMutatesExistingUnknownKeys(): void
    {
        mkdir($this->root . '/.agent-loop');
        $config = '{"version":1,"extra_custom":{"preserve":true},"prompting":{"language":"de-DE"}}' . "\n";
        file_put_contents($this->root . '/.agent-loop/init.json', $config);

        $command = new InitProposalCommand($this->root);
        self::assertSame([], $command->inspect()['changes']);
        self::assertSame([], $command->inspect('DE-de')['changes']);

        $changes = $command->inspect('fr-FR')['changes'];
        self::assertCount(1, $changes);
        self::assertSame('/prompting/language', $changes[0]['pointer']);
        self::assertSame('de-de', $changes[0]['before']);
        self::assertSame('fr-fr', $changes[0]['after']);
        self::assertSame('.agent-loop/init.json', $changes[0]['target']);
        self::assertSame('explicit --language', $changes[0]['source']);
        self::assertSame($config, file_get_contents($this->root . '/.agent-loop/init.json'));
    }

    public function testDetectedToolScopesStaySeparateAndScriptsAreNotExecuted(): void
    {
        mkdir($this->root . '/src');
        file_put_contents($this->root . '/phpstan.neon', "parameters:\n  paths:\n    - src\n");
        file_put_contents($this->root . '/composer.json', json_encode([
            'name' => 'example/consumer',
            'scripts' => [
                'ci' => 'php -r "file_put_contents(\"EXECUTED\", 1);"',
                'post-install-cmd' => 'php -r "file_put_contents(\"EXECUTED\", 1);"',
            ],
        ], JSON_THROW_ON_ERROR));

        $report = (new InitProposalCommand($this->root))->inspect();

        self::assertCount(2, $report['candidates']);
        self::assertSame('map.paths', $report['candidates'][0]['id']);
        self::assertSame('voku/agent-map', $report['candidates'][0]['owner']);
        self::assertSame('phpstan.neon', $report['candidates'][0]['source']);
        self::assertSame(['src'], $report['candidates'][0]['value']['paths']);
        self::assertSame('composer.quality-scripts', $report['candidates'][1]['id']);
        self::assertSame('declaration-only', $report['candidates'][1]['confidence']);
        self::assertSame(['ci'], $report['candidates'][1]['value']['script_names']);
        self::assertFileDoesNotExist($this->root . '/EXECUTED');
    }

    public function testUnsupportedArgumentsAndInvalidLanguageFailClosed(): void
    {
        $command = new InitProposalCommand($this->root);

        foreach ([
            ['--language=../escape'],
            ['--language='],
            ['--language=de-DE', '--language=fr-FR'],
            ['--format=xml'],
            ['--format=json', '--format=json'],
            ['--apply'],
        ] as $args) {
            ob_start();
            try {
                $exit = $command->run($args);
                $output = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }

            self::assertSame(1, $exit, implode(' ', $args));
            self::assertSame('', $output);
        }

        self::assertDirectoryDoesNotExist($this->root . '/.agent-loop');
    }

    public function testMalformedExistingInitConfigBlocksLanguageProposal(): void
    {
        mkdir($this->root . '/.agent-loop');
        $config = '{"prompting":{"language":42}}';
        file_put_contents($this->root . '/.agent-loop/init.json', $config);

        $command = new InitProposalCommand($this->root);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot propose a language edit');
        $command->inspect('de-DE');
    }
}
