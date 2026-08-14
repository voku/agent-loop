<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitSyncInstructionsCommand;

/** @internal */
final class InitSyncInstructionsCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-sync-instructions-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCodexCreatesTheSharedAlwaysOnRouter(): void
    {
        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        $agents = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString(InitSyncInstructionsCommand::BEGIN_MARKER, $agents);
        self::assertStringContainsString('agent-loop workflow router', $agents);
        self::assertStringContainsString('agent-loop-*', $agents);
        self::assertStringContainsString('agent-map', $agents);
        self::assertStringContainsString('agent-recall-compiler', $agents);
        self::assertStringContainsString('sync-githooks', $agents);
        self::assertFileDoesNotExist($this->root . '/CLAUDE.md');
        self::assertFileDoesNotExist($this->root . '/GEMINI.md');
    }

    public function testClaudeAndGeminiUseThinImportsInsteadOfDuplicatingTheRouter(): void
    {
        $result = $this->runCommand(['--agent=all']);

        self::assertSame(0, $result['exit'], $result['output']);
        $agents = (string) file_get_contents($this->root . '/AGENTS.md');
        $claude = (string) file_get_contents($this->root . '/CLAUDE.md');
        $gemini = (string) file_get_contents($this->root . '/GEMINI.md');

        self::assertStringContainsString('agent-loop workflow router', $agents);
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
