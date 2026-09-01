<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitInstallAssetsCommand;
use voku\AgentLoop\Init\InitSyncInstructionsCommand;

/** @internal */
final class InitSyncInstructionsCommandTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-sync-instructions-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        foreach (['CODEX_HOME', 'CODEX_SKILLS_DIR', 'CODEX_AGENTS_DIR'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        $this->removeDirectory($this->root);
    }

    public function testCodexCreatesTheSharedAlwaysOnRouter(): void
    {
        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        $agents = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString(InitSyncInstructionsCommand::BEGIN_MARKER, $agents);
        self::assertStringContainsString('agent-loop workflow router', $agents);
        self::assertStringContainsString('agent-loop enter <task-id>', $agents);
        self::assertStringContainsString('agent-loop finish <task-id>', $agents);
        // The router routes on the canonical contract rather than restating
        // lifecycle vocabulary. Mutation: ready / Complete: yes were the old
        // wording; a router quoting kernel output is host-owned choreography.
        self::assertStringContainsString('next_action_kind', $agents);
        foreach (['command', 'command_template', 'decision_required', 'host_work', 'none'] as $kind) {
            self::assertStringContainsString($kind, $agents, 'router must name the ' . $kind . ' step kind');
        }
        self::assertStringContainsString('genuine human-authority decision', $agents);
        self::assertStringContainsString('exact current decision subject', $agents);
        self::assertStringContainsString('do not pre-build Map/Search', $agents);
        self::assertStringContainsString('vendor/bin/agent-loop init status', $agents);
        self::assertStringContainsString('init sync-instructions', $agents);
        self::assertStringNotContainsString('agent-map', $agents);
        self::assertStringNotContainsString('agent-recall-compiler', $agents);
        self::assertFileDoesNotExist($this->root . '/CLAUDE.md');
        self::assertFileDoesNotExist($this->root . '/GEMINI.md');
    }

    public function testInstallAssetsAlsoCreatesTheAlwaysOnRouter(): void
    {
        ob_start();
        $exit = (new InitInstallAssetsCommand($this->root))->run(['--agent=codex']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertFileExists($this->root . '/AGENTS.md');
        $router = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString('agent-loop workflow router', $router);
        self::assertStringContainsString('agent-loop enter <task-id>', $router);
        self::assertStringContainsString('agent-loop finish <task-id>', $router);
        self::assertStringContainsString('[OK] sync instructions: updated AGENTS.md.', $output);
    }

    public function testClaudeAndGeminiUseThinImportsInsteadOfDuplicatingTheRouter(): void
    {
        $result = $this->runCommand(['--agent=all']);

        self::assertSame(0, $result['exit'], $result['output']);
        $agents = (string) file_get_contents($this->root . '/AGENTS.md');
        $claude = (string) file_get_contents($this->root . '/CLAUDE.md');
        $gemini = (string) file_get_contents($this->root . '/GEMINI.md');

        self::assertStringContainsString('agent-loop enter <task-id>', $agents);
        self::assertStringContainsString('agent-loop finish <task-id>', $agents);
        self::assertStringContainsString('@AGENTS.md', $claude);
        self::assertStringNotContainsString('agent-loop workflow router', $claude);
        self::assertStringContainsString('@./AGENTS.md', $gemini);
        self::assertStringNotContainsString('agent-loop workflow router', $gemini);
    }

    public function testRerunReplacesOnlyTheManagedBlockAndPreservesProjectText(): void
    {
        $existing = "# Project rules\n\nKeep this human-owned text.\n\n"
            . InitSyncInstructionsCommand::BEGIN_MARKER . "\nold generated text\n"
            . InitSyncInstructionsCommand::END_MARKER . "\n\n# More project rules\nStill human-owned.\n";
        file_put_contents($this->root . '/AGENTS.md', $existing);

        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        $updated = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString("# Project rules\n\nKeep this human-owned text.", $updated);
        self::assertStringContainsString("# More project rules\nStill human-owned.", $updated);
        self::assertStringNotContainsString('old generated text', $updated);
        self::assertStringContainsString('agent-loop enter <task-id>', $updated);
        self::assertStringContainsString('agent-loop finish <task-id>', $updated);
        self::assertSame(1, substr_count($updated, InitSyncInstructionsCommand::BEGIN_MARKER));
        self::assertSame(1, substr_count($updated, InitSyncInstructionsCommand::END_MARKER));
    }

    public function testExistingClaudeImportIsRecognizedWithoutClaimingOwnership(): void
    {
        $claude = "# Claude-specific rules\n\n@AGENTS.md\n\nKeep plan mode here.\n";
        file_put_contents($this->root . '/CLAUDE.md', $claude);

        $result = $this->runCommand(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame($claude, file_get_contents($this->root . '/CLAUDE.md'));
        self::assertStringContainsString('already imports AGENTS.md', $result['output']);
    }

    public function testMalformedMarkersFailWithoutRewritingTheFile(): void
    {
        $existing = "# Project\n\n" . InitSyncInstructionsCommand::BEGIN_MARKER . "\nbroken\n";
        file_put_contents($this->root . '/AGENTS.md', $existing);

        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertSame($existing, file_get_contents($this->root . '/AGENTS.md'));
    }

    public function testDryRunReportsChangesWithoutWriting(): void
    {
        $result = $this->runCommand(['--agent=all', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync instructions: update AGENTS.md', $result['output']);
        self::assertFileDoesNotExist($this->root . '/AGENTS.md');
        self::assertFileDoesNotExist($this->root . '/CLAUDE.md');
        self::assertFileDoesNotExist($this->root . '/GEMINI.md');
    }

    public function testRouterNamesACliPathThatExistsInTheProjectedRepository(): void
    {
        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'acme/app'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );

        self::assertSame(0, $this->runCommand(['--agent=codex'])['exit']);

        $consumerRouter = (string) file_get_contents($this->root . '/AGENTS.md');
        // The invariant is that the router names a CLI path that exists in the
        // projected repository, not the exact flags after the command.
        self::assertStringContainsString('`vendor/bin/agent-loop enter <task-id>', $consumerRouter);
        self::assertStringContainsString('`vendor/bin/agent-loop finish <task-id>', $consumerRouter);
        self::assertStringNotContainsString('{{agent_loop_cli}}', $consumerRouter);

        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'voku/agent-loop'], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );

        self::assertSame(0, $this->runCommand(['--agent=codex'])['exit']);

        $packageRouter = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString('`bin/agent-loop enter <task-id>', $packageRouter);
        self::assertStringContainsString('`bin/agent-loop finish <task-id>', $packageRouter);
        self::assertStringNotContainsString('`vendor/bin/agent-loop', $packageRouter);
    }

    public function testSymlinkedImportFileDoesNotOverwriteTheRouter(): void
    {
        // Reproduces the real breakage: CLAUDE.md is a symlink to AGENTS.md, so
        // projecting "@AGENTS.md" onto it followed the link, replaced the router
        // block with an import of itself and destroyed the instructions.
        $first = $this->runCommand(['--agent=claude']);
        self::assertSame(0, $first['exit'], $first['output']);

        $router = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString('agent-loop workflow router', $router);

        unlink($this->root . '/CLAUDE.md');
        symlink('AGENTS.md', $this->root . '/CLAUDE.md');

        $second = $this->runCommand(['--agent=claude']);

        self::assertSame(0, $second['exit'], $second['output']);
        self::assertTrue(is_link($this->root . '/CLAUDE.md'), 'the symlink must be left alone');
        self::assertSame(
            $router,
            file_get_contents($this->root . '/AGENTS.md'),
            'AGENTS.md must keep its router block',
        );
        self::assertStringNotContainsString(
            "\n@AGENTS.md\n",
            (string) file_get_contents($this->root . '/AGENTS.md'),
            'AGENTS.md must not end up importing itself',
        );
        self::assertStringContainsString('is a symlink to AGENTS.md', $second['output']);
    }

    public function testSymlinkedImportFileIsNotReportedAsPermanentlyStale(): void
    {
        self::assertSame(0, $this->runCommand(['--agent=claude'])['exit']);
        unlink($this->root . '/CLAUDE.md');
        symlink('AGENTS.md', $this->root . '/CLAUDE.md');

        // Otherwise `init host-status` keeps demanding the same destructive write.
        self::assertTrue((new InitSyncInstructionsCommand($this->root))->isCurrentFor('claude'));
    }

    public function testAPlainImportFileIsStillProjected(): void
    {
        $result = $this->runCommand(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFalse(is_link($this->root . '/CLAUDE.md'));
        self::assertStringContainsString('@AGENTS.md', (string) file_get_contents($this->root . '/CLAUDE.md'));
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runCommand(array $tokens): array
    {
        ob_start();
        $exit = (new InitSyncInstructionsCommand($this->root))->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
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
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
