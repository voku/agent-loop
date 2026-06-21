<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentLoopVerifier;

/**
 * Covers `--strict`: tasks/ and session_plan/ are the baseline inputs
 * `agent-loop verify` exists to confirm, so a missing directory becomes a
 * [FAIL] under --strict instead of the default [SKIP]. board (TODO.md) and
 * the learning root stay skippable even in strict mode -- both are
 * documented, opt-in additions on top of that baseline loop (see
 * README.md), not something every repo using `agent-loop` is expected to
 * have wired up.
 *
 * @internal
 */
final class AgentLoopVerifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-verifier-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDefaultModeSkipsMissingTasksAndSessions(): void
    {
        $result = $this->verify([]);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[SKIP] tasks: no directory at', $result['output']);
        self::assertStringContainsString('[SKIP] sessions: no directory at', $result['output']);
    }

    public function testStrictModeFailsWhenTasksAndSessionsAreMissing(): void
    {
        $result = $this->verify(['--strict']);

        self::assertSame(1, $result['exit'], $result['output']);
        self::assertStringContainsString('[FAIL] tasks: no directory at', $result['output']);
        self::assertStringContainsString('[FAIL] sessions: no directory at', $result['output']);
        self::assertStringContainsString('(required with --strict)', $result['output']);
        self::assertStringContainsString('[FAIL] agent-loop verify: drift detected, see above.', $result['output']);
    }

    public function testStrictModeStillSkipsBoardAndLearningRootOnceTasksAndSessionsExist(): void
    {
        mkdir($this->root . '/tasks', 0o775, true);
        file_put_contents($this->root . '/tasks/DEMO-1.md', "# Demo task\n\nBody.\n");
        mkdir($this->root . '/session_plan', 0o775, true);

        $result = $this->verify(['--strict']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[SKIP] board: no TODO.md', $result['output']);
        self::assertStringContainsString('[SKIP] learning root:', $result['output']);
        self::assertStringContainsString('[OK] agent-loop verify: no drift detected.', $result['output']);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function verify(array $tokens): array
    {
        ob_start();
        $exit = (new AgentLoopVerifier($this->root))->run($tokens);
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
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
