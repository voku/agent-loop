<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\InitSyncSubagentsCommand;

/** @internal */
final class OpenCodeHostProjectionTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        foreach (['OPENCODE_AGENTS_DIR'] as $name) {
            $value = getenv($name);
            $this->envBackup[$name] = $value === false ? false : $value;
            putenv($name);
        }

        $this->root = sys_get_temp_dir() . '/agent-loop-opencode-projection-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/docs/agents/subagents', 0o775, true) && !is_dir($this->root . '/docs/agents/subagents')) {
            throw new RuntimeException('Unable to create OpenCode projection fixture root.');
        }

        file_put_contents(
            $this->root . '/docs/agents/subagents/reviewer.md',
            "---\nname: reviewer\ndescription: Review code carefully\n---\n\nReview the implementation and report evidence.\n",
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv($name . '=' . $value);
        }

        $this->removeDirectory($this->root);
    }

    public function testOpenCodeAgentUsesFilenameIdentityAndNativeSubagentFrontmatter(): void
    {
        ob_start();
        try {
            $exit = (new InitSyncSubagentsCommand($this->root))->run(['--agent=opencode']);
            $output = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        self::assertSame(0, $exit, $output);
        $path = $this->root . '/.opencode/agents/reviewer.md';
        self::assertFileExists($path);

        $content = file_get_contents($path);
        self::assertIsString($content);
        self::assertStringContainsString("description: \"Review code carefully\"", $content);
        self::assertStringContainsString("mode: \"subagent\"", $content);
        self::assertStringNotContainsString("name: \"reviewer\"", $content);
        self::assertStringContainsString('Review the implementation and report evidence.', $content);
        self::assertFileExists($this->root . '/.opencode/agents/.agent-loop-manifest.json');
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
