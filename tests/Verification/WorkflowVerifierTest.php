<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests\Verification;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Verification\WorkflowVerifier;

/**
 * @internal
 */
final class WorkflowVerifierTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-workflow-verifier-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testAllCommandWiringChecksPassWhenDependenciesAreInstalled(): void
    {
        $result = (new WorkflowVerifier($this->root))->verify();

        self::assertFalse($result->hasFailures());
        self::assertSame(0, $result->exitCode());

        $rendered = $result->render();
        self::assertStringContainsString('[OK] board: board command is wired', $rendered);
        self::assertStringContainsString('[OK] board: board verifier is available', $rendered);
        self::assertStringContainsString('[OK] session: session command is wired', $rendered);
        self::assertStringContainsString('[OK] recall: recall command is wired', $rendered);
        self::assertStringContainsString('[OK] learn: learn command is wired', $rendered);
        self::assertStringContainsString('[OK] memory: memory review command is wired', $rendered);
    }

    public function testDocsCheckSkipsWhenReadmeIsMissing(): void
    {
        $rendered = (new WorkflowVerifier($this->root))->verify()->render();

        self::assertStringContainsString('[SKIP] docs: no README.md found at', $rendered);
    }

    public function testDocsCheckWarnsWhenReadmeDoesNotMentionWorkflowVerify(): void
    {
        file_put_contents($this->root . '/README.md', "# Some project\n\nNo workflow stuff here.\n");

        $rendered = (new WorkflowVerifier($this->root))->verify()->render();

        self::assertStringContainsString('[WARN] docs: README does not yet document workflow:verify', $rendered);
    }

    public function testDocsCheckPassesWhenReadmeMentionsWorkflowVerify(): void
    {
        file_put_contents($this->root . '/README.md', "# Some project\n\nRun `agent-loop workflow:verify`.\n");

        $result = (new WorkflowVerifier($this->root))->verify();

        self::assertFalse($result->hasFailures());
        self::assertStringContainsString('[OK] docs: README documents workflow:verify', $result->render());
    }

    public function testRunPrintsHeaderAndOverallStatusAndReturnsExitCode(): void
    {
        ob_start();
        $exit = (new WorkflowVerifier($this->root))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop workflow:verify - workflow wiring check', $output);
        self::assertStringContainsString('[OK] agent-loop workflow:verify: workflow wiring looks intact.', $output);
    }

    public function testRunHelpPrintsUsageAndExitsZeroWithoutRunningChecks(): void
    {
        ob_start();
        $exit = (new WorkflowVerifier($this->root))->run(['help']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);
        self::assertStringContainsString('agent-loop workflow:verify - workflow wiring check.', $output);
        self::assertStringNotContainsString('[OK] board:', $output);
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
