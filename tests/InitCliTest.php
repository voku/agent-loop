<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dispatcher;

/**
 * @internal
 */
final class InitCliTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-cli-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testInitHelpExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init doctor', $result['output']);
    }

    public function testInitLongHelpExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', '--help']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init install-plan', $result['output']);
    }

    public function testInitShortHelpExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', '-h']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('sync-hooks', $result['output']);
    }

    public function testUnknownInitCommandExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'unknown']);

        self::assertSame(1, $result['exit']);
    }

    public function testInitDoctorExitsZero(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'doctor']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init doctor', $result['output']);
    }

    public function testInitValidateWithoutKindExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'validate']);

        self::assertSame(1, $result['exit']);
    }

    public function testInitValidateSubagentsIsReserved(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'validate', '--kind=subagents']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('subagents validation is not implemented yet', $result['output']);
    }

    public function testInitValidateHooksIsReserved(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'validate', '--kind=hooks']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('hooks validation is not implemented yet', $result['output']);
    }

    public function testInitValidateAllExitsOneAfterSkillsValidation(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");

        $result = $this->dispatch(['agent-loop', 'init', 'validate', '--kind=all']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
        self::assertStringContainsString('subagents validation is not implemented yet', $result['output']);
        self::assertStringContainsString('hooks validation is not implemented yet', $result['output']);
    }

    public function testReservedSyncSkillsExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'sync-skills', '--agent=codex']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('init sync-skills is not implemented yet', $result['output']);
    }

    public function testReservedSyncSubagentsExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'sync-subagents', '--agent=copilot']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('init sync-subagents is not implemented yet', $result['output']);
    }

    public function testReservedSyncHooksExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'sync-hooks', '--agent=codex']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('init sync-hooks is not implemented yet', $result['output']);
    }

    public function testReservedScaffoldExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'scaffold', '--profile=wsl2', '--agent=codex', '--dry-run']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('init scaffold is not implemented yet', $result['output']);
    }

    public function testUnknownAgentExitsOne(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'install-plan', '--profile=wsl2', '--agent=nope']);

        self::assertSame(1, $result['exit']);
    }

    public function testGeminiAliasPrintsWarningAndResolvesToAntigravity(): void
    {
        $result = $this->dispatch(['agent-loop', 'init', 'install-plan', '--profile=wsl2', '--agent=gemini']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[WARN] Agent "gemini" is treated as a legacy Google coding-agent alias.', $result['output']);
        self::assertStringContainsString('[INFO] Using canonical agent "antigravity".', $result['output']);
        self::assertStringContainsString('Agent: antigravity', $result['output']);
    }

    /**
     * @param list<string> $argv
     *
     * @return array{exit: int, output: string}
     */
    private function dispatch(array $argv): array
    {
        $dispatcher = new Dispatcher($this->root);

        ob_start();
        $exit = $dispatcher->run($argv);
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
