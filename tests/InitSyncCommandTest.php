<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitSyncHooksCommand;
use voku\AgentLoop\Init\InitSyncSkillsCommand;
use voku\AgentLoop\Init\InitSyncSubagentsCommand;

/**
 * @internal
 */
final class InitSyncCommandTest extends TestCase
{
    private string $root;

    /**
     * @var array<string, string|false>
     */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-sync-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->backupEnv(['CODEX_HOME', 'CODEX_SKILLS_DIR', 'COPILOT_SKILLS_DIR', 'CLAUDE_SKILLS_DIR', 'ANTIGRAVITY_SKILLS_DIR', 'COPILOT_AGENTS_DIR', 'ANTIGRAVITY_AGENTS_DIR']);
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

    public function testSyncSkillsCopiesCanonicalDirectoriesIntoCodexTarget(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/notes.txt', "demo\n");

        $result = $this->runSyncSkills(['--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/.codex/skills/demo-skill/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/.agent-loop-manifest.json');
    }

    public function testSyncSkillsDryRunWritesNoFiles(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");

        $result = $this->runSyncSkills(['--agent=codex', '--dry-run']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[DRY-RUN] sync skills: install demo-skill', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.codex/skills');
    }

    public function testSyncSkillsRefusesToOverwriteUnmanagedTargetsWithoutForce(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        mkdir($this->root . '/.codex/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");
        file_put_contents($this->root . '/.codex/skills/demo-skill/SKILL.md', "# Existing\n");

        $result = $this->runSyncSkills(['--agent=codex']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('unmanaged target already exists', $result['output']);
    }

    public function testSyncSkillsAllowsGeminiAliasForAntigravityTarget(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");

        $result = $this->runSyncSkills(['--agent=gemini']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[INFO] Using canonical agent "antigravity".', $result['output']);
        self::assertFileExists($this->root . '/.agents/skills/demo-skill/SKILL.md');
    }

    public function testSyncSubagentsRendersCopilotTargets(): void
    {
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n");

        $result = $this->runSyncSubagents(['--agent=copilot']);

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/.github/agents/reviewer.agent.md');
        self::assertStringContainsString('name: "reviewer"', file_get_contents($this->root . '/.github/agents/reviewer.agent.md') ?: '');
    }

    public function testSyncSubagentsRendersAntigravityTargets(): void
    {
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n");

        $result = $this->runSyncSubagents(['--agent=antigravity']);

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/.agents/agents/reviewer.md');
        $content = file_get_contents($this->root . '/.agents/agents/reviewer.md') ?: '';
        self::assertStringContainsString('kind: "local"', $content);
        self::assertStringContainsString('max_turns: 12', $content);
    }

    public function testSyncHooksCopiesManifestAndScripts(): void
    {
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', json_encode([
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
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/session_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/subagent_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/pre_tool_use_policy.php', "<?php\nreturn;\n");

        $result = $this->runSyncHooks(['--agent=codex']);

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/.codex/hooks.json');
        self::assertFileExists($this->root . '/.codex/hooks/session_context.php');
        self::assertFileExists($this->root . '/.codex/.agent-loop-manifest.json');
    }

    public function testSyncHooksDryRunWritesNoFiles(): void
    {
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', json_encode([
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
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/session_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/subagent_context.php', "<?php\nreturn;\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/pre_tool_use_policy.php', "<?php\nreturn;\n");

        $result = $this->runSyncHooks(['--agent=codex', '--dry-run']);

        self::assertSame(0, $result['exit']);
        self::assertDirectoryDoesNotExist($this->root . '/.codex/hooks');
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runSyncSkills(array $tokens): array
    {
        $command = new InitSyncSkillsCommand($this->root);

        ob_start();
        $exit = $command->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runSyncSubagents(array $tokens): array
    {
        $command = new InitSyncSubagentsCommand($this->root);

        ob_start();
        $exit = $command->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runSyncHooks(array $tokens): array
    {
        $command = new InitSyncHooksCommand($this->root);

        ob_start();
        $exit = $command->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    /**
     * @param list<string> $names
     */
    private function backupEnv(array $names): void
    {
        foreach ($names as $name) {
            $value = getenv($name);
            $this->envBackup[$name] = $value === false ? false : $value;
            putenv($name);
        }
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
