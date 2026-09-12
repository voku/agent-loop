<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitSyncSubagentsCommand;

final class SubagentMutationProjectionIntegrationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-subagent-projection-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/resources/subagents', 0o775, true);
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

    public function testCodexSyncEnforcesOnlyExplicitReadOnlyMutationIntent(): void
    {
        file_put_contents(
            $this->root . '/resources/subagents/reviewer.md',
            "---\nname: reviewer\ndescription: Review only.\nmutation: read-only\n---\n\nReview and report.\n",
        );
        file_put_contents(
            $this->root . '/resources/subagents/builder.md',
            "---\nname: builder\ndescription: Apply a patch.\nmutation: writable\n---\n\nApply the patch.\n",
        );

        ob_start();
        $exit = (new InitSyncSubagentsCommand($this->root))->run(['--agent=codex']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        $reviewer = file_get_contents($this->root . '/.codex/agents/reviewer.toml');
        $builder = file_get_contents($this->root . '/.codex/agents/builder.toml');
        self::assertIsString($reviewer);
        self::assertIsString($builder);
        self::assertStringContainsString('sandbox_mode = "read-only"', $reviewer);
        self::assertStringNotContainsString('sandbox_mode', $builder);
    }
}
