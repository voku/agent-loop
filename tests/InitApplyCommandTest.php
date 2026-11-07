<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitApplyCommand;
use voku\AgentLoop\Init\InitLanguageConfigWriter;
use voku\AgentLoop\Init\InitPlanCommand;

/** @internal */
final class InitApplyCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-apply-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . '/.agent-loop', 0o700, true));
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    public function testOnlyExplicitAcceptedLanguageCanBeWrittenAndUnknownKeysSurvive(): void
    {
        $configPath = $this->root . '/.agent-loop/init.json';
        $original = '{"version":1,"prompting":{"language":"de-DE","blindspots":{"tone":"direct"}},"other":{"keep":[1,2]}}';
        file_put_contents($configPath, $original);

        $plan = (new InitPlanCommand($this->root))->inspect('fr-FR');
        $args = [
            '--language=fr-FR',
            '--evidence-sha256=' . $plan['evidence_sha256'],
            '--accept=prompting.language',
        ];
        $command = new InitApplyCommand($this->root);

        ob_start();
        try {
            $exit = $command->run([...$args, '--dry-run']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit);
        self::assertSame('dry_run', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status']);
        self::assertSame($original, file_get_contents($configPath));
        self::assertFileDoesNotExist($this->root . '/.agent-loop/.init-language.lock');

        ob_start();
        try {
            $exit = $command->run([...$args, '--yes']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
        self::assertSame(0, $exit);
        self::assertSame('applied', json_decode($output, true, 512, JSON_THROW_ON_ERROR)['status']);
        $decoded = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fr-fr', $decoded['prompting']['language']);
        self::assertSame('direct', $decoded['prompting']['blindspots']['tone']);
        self::assertSame([1, 2], $decoded['other']['keep']);

        $current = (new InitPlanCommand($this->root))->inspect('fr-FR');
        self::assertSame([], $current['review_diffs']);
        self::assertSame('unchanged', (new InitLanguageConfigWriter($this->root))->apply('fr-fr', $current['evidence_sha256']));
    }

    public function testStaleOrUnapprovedEvidenceCannotChangeExistingFile(): void
    {
        $configPath = $this->root . '/.agent-loop/init.json';
        file_put_contents($configPath, '{"version":1,"prompting":{"language":"de"}}');
        $plan = (new InitPlanCommand($this->root))->inspect('fr');
        $command = new InitApplyCommand($this->root);
        $valid = ['--language=fr', '--evidence-sha256=' . $plan['evidence_sha256'], '--accept=prompting.language'];

        foreach ([
            ['--language=fr', '--evidence-sha256=' . $plan['evidence_sha256'], '--yes'],
            ['--language=fr', '--evidence-sha256=' . str_repeat('0', 64), '--accept=prompting.language', '--yes'],
            [...$valid, '--yes', '--dry-run'],
            [...$valid, '--accept=prompting.language'],
            ['--language=../escape', '--evidence-sha256=' . $plan['evidence_sha256'], '--accept=prompting.language', '--yes'],
        ] as $args) {
            ob_start();
            try {
                $exit = $command->run($args);
                $stdout = (string) ob_get_contents();
            } finally {
                ob_end_clean();
            }
            self::assertSame(1, $exit);
            self::assertSame('', $stdout);
        }
        self::assertFileDoesNotExist($this->root . '/.agent-loop/.init-language.lock');

        file_put_contents($configPath, '{"version":1,"prompting":{"language":"es"},"new":"owner-edit"}');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Setup evidence changed');
        (new InitLanguageConfigWriter($this->root))->apply('fr', $plan['evidence_sha256']);
    }

    public function testSymlinkedConfigTargetCannotBeOverwritten(): void
    {
        $outside = $this->root . '/outside.json';
        file_put_contents($outside, '{"version":1}');
        $configPath = $this->root . '/.agent-loop/init.json';
        self::assertTrue(symlink($outside, $configPath));

        $this->expectException(\RuntimeException::class);
        (new InitLanguageConfigWriter($this->root))->apply('de', str_repeat('0', 64));
    }

    public function testNewConfigCanBeCreatedOnlyWithExplicitApproval(): void
    {
        $plan = (new InitPlanCommand($this->root))->inspect('de-DE');
        $configPath = $this->root . '/.agent-loop/init.json';
        $command = new InitApplyCommand($this->root);
        $args = [
            '--language=de-DE',
            '--evidence-sha256=' . $plan['evidence_sha256'],
            '--accept=prompting.language',
            '--yes',
        ];

        ob_start();
        try {
            $exit = $command->run($args);
            $json = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit);
        self::assertSame('applied', json_decode($json, true, 512, JSON_THROW_ON_ERROR)['status']);
        $saved = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $saved['version']);
        self::assertSame('de-de', $saved['prompting']['language']);
    }
}
