<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitInstallPlanCommand;

/**
 * @internal
 */
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

    public function testInstallPlanForCodexExitsZero(): void
    {
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Profile: wsl2', $result['output']);
        self::assertStringContainsString('Agent: codex', $result['output']);
        self::assertStringContainsString('codex plugin marketplace add DietrichGebert/ponytail', $result['output']);
        self::assertStringContainsString('codex plugin add ponytail@ponytail', $result['output']);
        self::assertStringContainsString('Open `/hooks`, review the Ponytail lifecycle hooks', $result['output']);
        self::assertCommonBlocks($result['output'], 'wsl2');
    }

    public function testInstallPlanForClaudeExitsZero(): void
    {
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=claude']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Agent: claude', $result['output']);
        self::assertStringContainsString('/plugin marketplace add DietrichGebert/ponytail', $result['output']);
        self::assertStringContainsString('/plugin install ponytail@ponytail', $result['output']);
        self::assertStringContainsString('Run these as two separate commands inside Claude Code:', $result['output']);
        self::assertCommonBlocks($result['output'], 'wsl2');
    }

    public function testInstallPlanForAntigravityExitsZero(): void
    {
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=antigravity']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Agent: antigravity', $result['output']);
        self::assertStringContainsString('agy plugin install https://github.com/DietrichGebert/ponytail', $result['output']);
        self::assertStringContainsString('gemini extensions install https://github.com/DietrichGebert/ponytail', $result['output']);
        self::assertCommonBlocks($result['output'], 'wsl2');
    }

    public function testInstallPlanForNativeLinuxExitsZero(): void
    {
        $result = $this->runInstallPlan(['--profile=linux', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Profile: linux', $result['output']);
        self::assertStringContainsString('Native Linux setup:', $result['output']);
        self::assertStringContainsString('Restart Codex inside Linux after installation.', $result['output']);
        self::assertStringContainsString('Important native Linux boundary:', $result['output']);
        self::assertStringNotContainsString('C:\Users\<you>\.claude', $result['output']);
        self::assertCommonBlocks($result['output'], 'linux');
    }

    public function testInstallPlanForWindowsExitsZero(): void
    {
        $result = $this->runInstallPlan(['--profile=windows', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Profile: windows', $result['output']);
        self::assertStringContainsString('Windows PowerShell setup:', $result['output']);
        self::assertStringContainsString('winget install BurntSushi.ripgrep.MSVC', $result['output']);
        self::assertStringContainsString('caveman-install.ps1', $result['output']);
        self::assertStringContainsString('rg --version', $result['output']);
        self::assertStringContainsString('Restart Codex inside Windows after installation.', $result['output']);
        self::assertStringContainsString('Important Windows boundary:', $result['output']);
        self::assertStringNotContainsString('rtk', strtolower($result['output']));
    }

    public function testInstallPlanForGeminiAliasExitsZeroWithWarning(): void
    {
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=gemini']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[WARN] Agent "gemini" is treated as a legacy Google coding-agent alias.', $result['output']);
        self::assertStringContainsString('[INFO] Using canonical agent "antigravity".', $result['output']);
        self::assertStringContainsString('Agent: antigravity', $result['output']);
    }

    public function testInstallPlanWithUnknownProfileExitsOne(): void
    {
        $result = $this->runInstallPlan(['--profile=macos', '--agent=codex']);

        self::assertSame(1, $result['exit']);
    }

    public function testInstallPlanWithUnknownAgentExitsOne(): void
    {
        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=nope']);

        self::assertSame(1, $result['exit']);
    }

    public function testInstallPlanWritesNoFiles(): void
    {
        $before = $this->listFiles($this->root);

        $result = $this->runInstallPlan(['--profile=wsl2', '--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertSame($before, $this->listFiles($this->root));
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runInstallPlan(array $tokens): array
    {
        $command = new InitInstallPlanCommand();

        ob_start();
        $exit = $command->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    private static function assertCommonBlocks(string $output, string $profile): void
    {
        self::assertStringContainsString('This command prints a setup plan only.', $output);
        self::assertStringContainsString('ripgrep (rg):', $output);
        self::assertStringContainsString('sudo apt install -y ripgrep', $output);
        self::assertStringContainsString('rg --version', $output);
        self::assertStringContainsString('curl -fsSL https://raw.githubusercontent.com/JuliusBrussee/caveman/main/install.sh -o /tmp/caveman-install.sh', $output);
        self::assertStringContainsString('Ponytail:', $output);
        self::assertStringContainsString('/caveman full', $output);
        self::assertStringContainsString('/ponytail full', $output);
        self::assertStringContainsString('must not rewrite shell commands, source files,', $output);
        self::assertStringNotContainsString('rtk', strtolower($output));
        self::assertStringContainsString($profile === 'linux' ? 'Important native Linux boundary:' : 'Important WSL2 boundary:', $output);
    }

    /**
     * @return list<string>
     */
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
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
