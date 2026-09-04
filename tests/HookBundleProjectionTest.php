<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitSyncHooksCommand;
use voku\AgentLoop\Init\InitValidateCommand;

final class HookBundleProjectionTest extends TestCase
{
    private string $root;

    /** @var array<string, false|string> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-hook-bundle-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        foreach (['CODEX_HOME', 'CLAUDE_CONFIG_DIR'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        $this->removeDirectory($this->root);
    }

    public function testCodexSyncProjectsUnregisteredHelperAndRemovesItWhenBundleStopsOwningIt(): void
    {
        $root = $this->root . '/resources/hooks/codex';
        $this->writeCodexBundle($root, true);

        $sync = $this->runSync(['--agent=codex']);

        self::assertSame(0, $sync['exit'], $sync['output']);
        self::assertFileExists($this->root . '/.codex/hooks/runtime.php');
        self::assertSame('runtime-ok', require $this->root . '/.codex/hooks/session_context.php');

        $manifest = json_decode(
            (string) file_get_contents($this->root . '/.codex/.agent-loop-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertContains('hooks/runtime.php', array_column($manifest['entries'], 'target'));

        unlink($root . '/hooks/runtime.php');
        file_put_contents($root . '/hooks/session_context.php', "<?php\n\nreturn 'standalone';\n");

        $second = $this->runSync(['--agent=codex']);

        self::assertSame(0, $second['exit'], $second['output']);
        self::assertStringContainsString('removed stale', $second['output']);
        self::assertFileDoesNotExist($this->root . '/.codex/hooks/runtime.php');
        self::assertSame('standalone', require $this->root . '/.codex/hooks/session_context.php');
    }

    public function testClaudeSyncProjectsUnregisteredHelperBesideRegisteredEntry(): void
    {
        $root = $this->root . '/resources/hooks/claude';
        mkdir($root . '/hooks', 0o775, true);
        file_put_contents($root . '/hooks/runtime.php', "<?php\n\nreturn 'runtime-ok';\n");
        file_put_contents($root . '/hooks/policy.php', "<?php\n\nreturn require __DIR__ . '/runtime.php';\n");
        file_put_contents($root . '/hooks.json', json_encode([
            'hooks' => [
                'PreToolUse' => [[
                    'matcher' => '^Bash$',
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php .claude/hooks/policy.php',
                    ]],
                ]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        $sync = $this->runSync(['--agent=claude']);

        self::assertSame(0, $sync['exit'], $sync['output']);
        self::assertFileExists($this->root . '/.claude/hooks/runtime.php');
        self::assertSame('runtime-ok', require $this->root . '/.claude/hooks/policy.php');

        $manifest = json_decode(
            (string) file_get_contents($this->root . '/.claude/.agent-loop-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertContains('hooks/runtime.php', array_column($manifest['entries'], 'target'));
    }

    public function testValidationRejectsMalformedUnregisteredPhpHelperWithoutExecutingIt(): void
    {
        $root = $this->root . '/resources/hooks/codex';
        $this->writeCodexBundle($root, true);
        file_put_contents($root . '/hooks/runtime.php', "<?php\n\nif (\n");

        $validation = $this->runValidate(['--kind=hooks', '--agent=codex']);

        self::assertSame(1, $validation['exit']);
        self::assertStringContainsString('Hook PHP syntax error in hooks/runtime.php:', $validation['output']);
        self::assertStringNotContainsString('Stack trace', $validation['output']);
    }

    private function writeCodexBundle(string $root, bool $withRuntime): void
    {
        mkdir($root . '/hooks', 0o775, true);
        if ($withRuntime) {
            file_put_contents($root . '/hooks/runtime.php', "<?php\n\nreturn 'runtime-ok';\n");
        }
        file_put_contents(
            $root . '/hooks/session_context.php',
            $withRuntime
                ? "<?php\n\nreturn require __DIR__ . '/runtime.php';\n"
                : "<?php\n\nreturn 'standalone';\n",
        );
        file_put_contents($root . '/hooks/subagent_context.php', "<?php\n\nreturn;\n");
        file_put_contents($root . '/hooks/pre_tool_use_policy.php', "<?php\n\nreturn;\n");
        file_put_contents($root . '/hooks.json', json_encode([
            'hooks' => [
                'SessionStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/session_context.php',
                    ]],
                ]],
                'SubagentStart' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/subagent_context.php',
                    ]],
                ]],
                'PreToolUse' => [[
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php $(git rev-parse --show-toplevel)/.codex/hooks/pre_tool_use_policy.php',
                    ]],
                ]],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runSync(array $tokens): array
    {
        ob_start();
        $exit = (new InitSyncHooksCommand($this->root))->run($tokens);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runValidate(array $tokens): array
    {
        ob_start();
        $exit = (new InitValidateCommand($this->root))->run($tokens);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
