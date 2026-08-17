<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitInstallPlanCommand;

/** @internal */
final class InitInstallPlanCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-install-plan-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testInstallPlanForCodexUsesFirstPartyAssets(): void
    {
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Profile: wsl2', $result['output']);
        self::assertStringContainsString('Agent: codex', $result['output']);
        self::assertStringContainsString('init install-assets --agent=codex --dry-run', $result['output']);
        self::assertStringContainsString('investigator, surgical-builder', $result['output']);
        self::assertStringContainsString('open `/hooks`', $result['output']);
        self::assertOfflineContract($result['output']);
    }

    public function testInstallPlanForClaudeUsesPortableSkills(): void
    {
        $result = $this->runInstallPlan(['--profile=linux', '--agent=claude']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Agent: claude', $result['output']);
        self::assertStringContainsString('init install-assets --agent=claude', $result['output']);
        self::assertStringContainsString('Restart Claude Code inside Linux', $result['output']);
        self::assertOfflineContract($result['output']);
    }

    public function testInstallPlanForCopilotIsSupported(): void
    {
        $result = $this->runInstallPlan(['--profile=windows', '--agent=copilot']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Agent: copilot', $result['output']);
        self::assertStringContainsString('init install-assets --agent=copilot', $result['output']);
        self::assertStringContainsString('Restart or reload skills and repository agents in Copilot inside Windows', $result['output']);
        self::assertOfflineContract($result['output']);
    }

    public function testInstallPlanForGeminiUsesGeminiAssets(): void
    {
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=gemini-cli']);

        self::assertSame(0, $result['exit']);
        self::assertStringNotContainsString('legacy', strtolower($result['output']));
        self::assertStringContainsString('Agent: gemini', $result['output']);
        self::assertStringContainsString('init install-assets --agent=gemini', $result['output']);
        self::assertStringContainsString('Restart Gemini CLI inside WSL2', $result['output']);
        self::assertOfflineContract($result['output']);
    }

    public function testUnknownProfileFails(): void
    {
        self::assertSame(1, $this->runInstallPlan(['--profile=macos', '--agent=codex'])['exit']);
    }

    public function testUnknownAgentFails(): void
    {
        self::assertSame(1, $this->runInstallPlan(['--profile=wsl2', '--agent=nope'])['exit']);
    }

    public function testInstallPlanWritesNoFiles(): void
    {
        $before = $this->listFiles($this->root);
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertSame($before, $this->listFiles($this->root));
    }

    private static function assertOfflineContract(string $output): void
    {
        self::assertStringContainsString('rg --version', $output);
        self::assertStringContainsString('does not fetch or execute an installer', $output);
        self::assertStringContainsString('shipped inside the installed `voku/agent-loop` package', $output);
        foreach ([
            'raw.githubusercontent.com',
            'github.com/',
            'curl ',
            'Invoke-WebRequest',
            'npm ',
            'node -v',
            'plugin install',
            'caveman',
            'ponytail',
            'rtk',
            'apt install',
            'winget install',
        ] as $forbidden) {
            self::assertStringNotContainsString(
                strtolower($forbidden),
                strtolower($output),
                'Unexpected remote bootstrap guidance: ' . $forbidden,
            );
        }
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runInstallPlan(array $tokens): array
    {
        ob_start();
        $exit = (new InitInstallPlanCommand())->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    /** @return list<string> */
    private function listFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            $files[] = str_replace($path . '/', '', $item->getPathname());
        }
        sort($files);

        return $files;
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
