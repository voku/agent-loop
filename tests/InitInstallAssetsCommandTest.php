<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Init\InitInstallAssetsCommand;

/** @internal */
final class InitInstallAssetsCommandTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-install-assets-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        foreach ([
            'CODEX_HOME',
            'CODEX_SKILLS_DIR',
            'CODEX_AGENTS_DIR',
            'CLAUDE_CONFIG_DIR',
            'CLAUDE_SKILLS_DIR',
            'COPILOT_SKILLS_DIR',
            'ANTIGRAVITY_SKILLS_DIR',
            'COPILOT_AGENTS_DIR',
            'ANTIGRAVITY_AGENTS_DIR',
        ] as $name) {
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

    public function testCodexDryRunUsesBundledAssetsWithoutWriting(): void
    {
        $result = $this->runCommand(['--agent=codex', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync skills: install agent-loop-discipline', $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync skills: install agent-loop-investigate', $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync skills: install agent-recall-consumer', $result['output']);
        self::assertStringContainsString('from 2 source root(s)', $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync subagents: install agent-loop-investigator.toml', $result['output']);
        self::assertStringNotContainsString('[DRY-RUN] sync hooks:', $result['output']);
        self::assertStringContainsString('executable host hooks were not registered', $result['output']);
        self::assertStringContainsString('first-party package guidance validated; no files written', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.codex');
        self::assertDirectoryDoesNotExist($this->root . '/.github/agents');
        self::assertStringNotContainsString('raw.githubusercontent.com', $result['output']);
        self::assertStringNotContainsString('plugin marketplace', strtolower($result['output']));
    }

    public function testCodexWithHooksDryRunShowsExactHookTargetsWithoutWriting(): void
    {
        $result = $this->runCommand(['--agent=codex', '--with-hooks', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync hooks: install hooks.json', $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync hooks: install context.php', $result['output']);
        self::assertStringContainsString('executable host hooks were explicitly requested with --with-hooks', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.codex');
    }

    public function testCodexInstallsBundledSkillsAndRolesWithoutExecutableHooks(): void
    {
        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-investigate/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-surgical-edit/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-code-review/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-simplify-review/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-simplify-audit/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-dogfood/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-recall-consumer/operating-prompts.json');
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-investigator.toml');
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-surgical-builder.toml');
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-code-reviewer.toml');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks.json');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks/context.php');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks/pre_tool_use_policy.php');
        self::assertDirectoryDoesNotExist($this->root . '/.github/agents');
        self::assertStringContainsString('executable host hooks were not registered', $result['output']);
        self::assertStringContainsString('without downloading remote code', $result['output']);
    }

    public function testConfigIncludesRepositoryConfiguredSkillsAndSubagents(): void
    {
        mkdir($this->root . '/custom-skills/my-custom-skill', 0o775, true);
        file_put_contents(
            $this->root . '/custom-skills/my-custom-skill/SKILL.md',
            "---\nname: my-custom-skill\ndescription: Custom skill.\n---\n\nSkill content.\n",
        );
        mkdir($this->root . '/custom-subagents', 0o775, true);
        file_put_contents(
            $this->root . '/custom-subagents/my-custom-subagent.md',
            "---\nname: my-custom-subagent\ndescription: Custom subagent.\n---\n\nSubagent prompt.\n",
        );
        file_put_contents(
            $this->root . '/custom-init.json',
            json_encode([
                'version' => 1,
                'paths' => [
                    'skills_root' => 'custom-skills',
                    'subagents_root' => 'custom-subagents',
                ],
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n",
        );

        $result = $this->runCommand(['--agent=codex', '--config=custom-init.json']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/my-custom-skill/SKILL.md');
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-investigator.toml');
        self::assertFileExists($this->root . '/.codex/agents/my-custom-subagent.toml');
    }

    public function testClaudePreservesExistingSettingsAndDoesNotRegisterExecutableHooks(): void
    {
        mkdir($this->root . '/.claude', 0o775, true);
        file_put_contents($this->root . '/.claude/settings.json', json_encode([
            'model' => 'opus',
            'theme' => 'auto',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $result = $this->runCommand(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.claude/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.claude/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.claude/agents/agent-loop-investigator.md');
        self::assertFileExists($this->root . '/.claude/agents/agent-loop-surgical-builder.md');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/context.php');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/pre_tool_use_policy.php');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks.json');
        self::assertDirectoryDoesNotExist($this->root . '/.codex/agents');
        self::assertDirectoryDoesNotExist($this->root . '/.github/agents');
        self::assertStringContainsString('executable host hooks were not registered', $result['output']);
        self::assertStringContainsString('rerun with --with-hooks to opt in', $result['output']);

        $settings = json_decode((string) file_get_contents($this->root . '/.claude/settings.json'), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame([
            'model' => 'opus',
            'theme' => 'auto',
        ], $settings);
    }

    public function testClaudeDryRunInstallsNothing(): void
    {
        $result = $this->runCommand(['--agent=claude', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringNotContainsString('[DRY-RUN] sync hooks:', $result['output']);
        self::assertStringContainsString('executable host hooks were not registered', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.claude/settings.json');
        self::assertDirectoryDoesNotExist($this->root . '/.claude/hooks');
    }

    public function testCopilotInstallsPortableSkillsAndSubagentRoles(): void
    {
        $result = $this->runCommand(['--agent=copilot']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.github/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.github/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.github/agents/agent-loop-investigator.agent.md');
        self::assertFileExists($this->root . '/.github/agents/agent-loop-surgical-builder.agent.md');
        self::assertFileExists($this->root . '/.github/agents/agent-loop-code-reviewer.agent.md');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks.json');
        self::assertStringContainsString('executable host hooks were not registered', $result['output']);
    }

    public function testUnsupportedHostRejectsWithHooksBeforeWriting(): void
    {
        $result = $this->runCommand(['--agent=copilot', '--with-hooks']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('--with-hooks is only supported for codex, claude, or all', $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.github');
    }

    public function testAllInstallsSkillsAndSubagentRolesWithoutExecutableHooks(): void
    {
        $result = $this->runCommand(['--agent=all']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.claude/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.github/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.agents/skills/agent-loop-discipline/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.claude/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.github/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.agents/skills/agent-recall-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-investigator.toml');
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-surgical-builder.toml');
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-code-reviewer.toml');
        self::assertFileExists($this->root . '/.github/agents/agent-loop-investigator.agent.md');
        self::assertFileExists($this->root . '/.github/agents/agent-loop-surgical-builder.agent.md');
        self::assertFileExists($this->root . '/.github/agents/agent-loop-code-reviewer.agent.md');
        self::assertFileExists($this->root . '/.agents/agents/agent-loop-investigator.md');
        self::assertFileExists($this->root . '/.agents/agents/agent-loop-surgical-builder.md');
        self::assertFileExists($this->root . '/.agents/agents/agent-loop-code-reviewer.md');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks.json');
        self::assertFileDoesNotExist($this->root . '/.codex/hooks/context.php');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/context.php');
        self::assertFileDoesNotExist($this->root . '/.claude/settings.json');
        self::assertStringContainsString('executable host hooks were not registered', $result['output']);
    }

    public function testAllWithHooksExplicitlyInstallsCodexAndClaudeHooks(): void
    {
        $result = $this->runCommand(['--agent=all', '--with-hooks']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/hooks.json');
        self::assertFileExists($this->root . '/.codex/hooks/context.php');
        self::assertFileExists($this->root . '/.claude/hooks/context.php');
        self::assertFileExists($this->root . '/.claude/settings.json');
        self::assertStringContainsString('executable host hooks were explicitly requested with --with-hooks', $result['output']);
    }

    public function testUnknownAgentFails(): void
    {
        self::assertSame(1, $this->runCommand(['--agent=nope'])['exit']);
    }

    public function testUnknownOptionFails(): void
    {
        self::assertSame(1, $this->runCommand(['--agent=codex', '--download'])['exit']);
    }

    public function testExtraSubagentsRootMergesSubagents(): void
    {
        mkdir($this->root . '/extra-subagents', 0o775, true);
        file_put_contents(
            $this->root . '/extra-subagents/extra-worker.md',
            "---\nname: extra-worker\ndescription: Extra worker.\n---\n\nWorker prompt.\n",
        );

        $result = $this->runCommand(['--agent=codex', '--extra-subagents-root=extra-subagents']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/agents/agent-loop-investigator.toml');
        self::assertFileExists($this->root . '/.codex/agents/extra-worker.toml');
    }

    public function testDeclaredGitHookPolicyIsActivatedInTheSameRun(): void
    {
        $runner = new ProcessRunner($this->root);
        if ($runner->run(['git', '--version'])['exit_code'] !== 0) {
            self::markTestSkipped('git is not available.');
        }

        $runner->mustRun(['git', 'init', '--quiet']);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{}\n");
        file_put_contents($this->root . '/.gitmessage', "# template\n");

        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.githooks/pre-commit');
        self::assertSame('.githooks', trim($runner->mustRun(['git', 'config', '--get', 'core.hooksPath'])['stdout']));
        self::assertSame('.gitmessage', trim($runner->mustRun(['git', 'config', '--get', 'commit.template'])['stdout']));
    }

    public function testGitConfigIsLeftAloneWhenTheRepositoryDeclaresNoHookPolicy(): void
    {
        $runner = new ProcessRunner($this->root);
        if ($runner->run(['git', '--version'])['exit_code'] !== 0) {
            self::markTestSkipped('git is not available.');
        }

        $runner->mustRun(['git', 'init', '--quiet']);

        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertDirectoryDoesNotExist($this->root . '/.githooks');
        self::assertSame(1, $runner->run(['git', 'config', '--get', 'core.hooksPath'])['exit_code']);
    }

    public function testSkipGitConfigInstallsHookFilesWithoutPointingGitAtThem(): void
    {
        $runner = new ProcessRunner($this->root);
        if ($runner->run(['git', '--version'])['exit_code'] !== 0) {
            self::markTestSkipped('git is not available.');
        }

        $runner->mustRun(['git', 'init', '--quiet']);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{}\n");

        $result = $this->runCommand(['--agent=codex', '--skip-git-config']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.githooks/pre-commit');
        self::assertSame(1, $runner->run(['git', 'config', '--get', 'core.hooksPath'])['exit_code']);
    }

    public function testDisabledPackageSkillsAndSubagentsViaConfig(): void
    {
        mkdir($this->root . '/.agent-loop', 0o775, true);
        mkdir($this->root . '/custom-skills/my-skill', 0o775, true);
        file_put_contents(
            $this->root . '/custom-skills/my-skill/SKILL.md',
            "---\nname: my-skill\ndescription: Custom skill.\n---\n\nSkill body.\n",
        );
        mkdir($this->root . '/custom-subagents', 0o775, true);
        file_put_contents(
            $this->root . '/custom-subagents/my-worker.md',
            "---\nname: my-worker\ndescription: Custom worker.\n---\n\nWorker prompt.\n",
        );

        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'package_skills' => false,
                'package_subagents' => false,
                'paths' => [
                    'skills_root' => 'custom-skills',
                    'subagents_root' => 'custom-subagents',
                ],
            ], JSON_PRETTY_PRINT),
        );

        $result = $this->runCommand(['--agent=codex']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.codex/skills/my-skill/SKILL.md');
        self::assertFileDoesNotExist($this->root . '/.codex/skills/agent-loop-workflow/SKILL.md');
        self::assertFileExists($this->root . '/.codex/agents/my-worker.toml');
        self::assertFileDoesNotExist($this->root . '/.codex/agents/agent-loop-investigator.toml');
    }

    public function testDisabledPackageSkillsAndSubagentsViaCliFlags(): void
    {
        mkdir($this->root . '/custom-skills/my-skill', 0o775, true);
        file_put_contents(
            $this->root . '/custom-skills/my-skill/SKILL.md',
            "---\nname: my-skill\ndescription: Custom skill.\n---\n\nSkill body.\n",
        );
        mkdir($this->root . '/custom-subagents', 0o775, true);
        file_put_contents(
            $this->root . '/custom-subagents/my-worker.md',
            "---\nname: my-worker\ndescription: Custom worker.\n---\n\nWorker prompt.\n",
        );

        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'paths' => [
                    'skills_root' => 'custom-skills',
                    'subagents_root' => 'custom-subagents',
                ],
            ], JSON_PRETTY_PRINT),
        );

        $result = $this->runCommand(['--agent=claude', '--no-package-skills', '--no-package-subagents']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.claude/skills/my-skill/SKILL.md');
        self::assertFileDoesNotExist($this->root . '/.claude/skills/agent-loop-workflow/SKILL.md');
        self::assertFileExists($this->root . '/.claude/agents/my-worker.md');
        self::assertFileDoesNotExist($this->root . '/.claude/agents/agent-loop-investigator.md');
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runCommand(array $tokens): array
    {
        ob_start();
        $exit = (new InitInstallAssetsCommand($this->root))->run($tokens);
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
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
