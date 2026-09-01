<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitInstallAssetsCommand;

/** @internal */
final class InstalledCodeReviewReachabilityTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-review-reachability-' . bin2hex(random_bytes(6));
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

    public function testCleanInstalledConsumerDoesNotRequireOptionalEngineeringLens(): void
    {
        ob_start();
        $exit = (new InitInstallAssetsCommand($this->root))->run(['--agent=codex']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);

        $skillPath = $this->root . '/.codex/skills/agent-loop-code-review/SKILL.md';
        $reviewerPath = $this->root . '/.codex/agents/agent-loop-code-reviewer.toml';
        self::assertFileExists($skillPath);
        self::assertFileExists($reviewerPath);

        $skill = (string) file_get_contents($skillPath);
        $reviewer = (string) file_get_contents($reviewerPath);

        self::assertStringContainsString(
            'A normal review must remain executable when no optional `code-review-*` engineering lens is installed.',
            $skill,
        );
        self::assertStringContainsString(
            'guaranteed default correctness-review capability',
            $reviewer,
        );
        self::assertStringContainsString(
            'do not block merely because no optional lens is installed',
            $reviewer,
        );
        self::assertStringContainsString('review code <task-id>', $skill);
        self::assertStringContainsString('review first-draft', $skill);
        self::assertStringNotContainsString(
            'UNKNOWN: no applicable code-review-* capability is available.',
            $skill . "\n" . $reviewer,
        );
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
