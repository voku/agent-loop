<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitStatusCommand;

/**
 * @internal
 */
final class InitStatusCommandTest extends TestCase
{
    private string $root;

    /**
     * @var array<string, string|false>
     */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-status-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
        $this->backupEnv(['CODEX_HOME', 'CODEX_SKILLS_DIR', 'CLAUDE_SKILLS_DIR', 'COPILOT_SKILLS_DIR', 'ANTIGRAVITY_SKILLS_DIR', 'COPILOT_AGENTS_DIR', 'ANTIGRAVITY_AGENTS_DIR']);
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

    public function testStatusExitsZeroInEmptyTempRepo(): void
    {
        $result = $this->runStatus([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('agent-loop init status', $result['output']);
    }

    public function testStatusWritesNoFiles(): void
    {
        $before = $this->listFiles($this->root);

        $result = $this->runStatus([]);

        self::assertSame(0, $result['exit']);
        self::assertSame($before, $this->listFiles($this->root));
    }

    public function testStatusReportsDefaultSourcePaths(): void
    {
        $result = $this->runStatus([]);

        self::assertStringContainsString('[OK] skills-root: docs/agents/skills', $result['output']);
        self::assertStringContainsString('[OK] subagents-root: docs/agents/subagents', $result['output']);
        self::assertStringContainsString('[OK] hooks-root: docs/agents/codex-hooks', $result['output']);
        self::assertStringContainsString('[INFO] tools-root: docs/agents/tools', $result['output']);
    }

    public function testStatusRespectsSkillsRootOption(): void
    {
        mkdir($this->root . '/custom/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/custom/skills/demo-skill/SKILL.md', "# Demo\n");

        $result = $this->runStatus(['--skills-root=custom/skills']);

        self::assertStringContainsString('[OK] skills-root: custom/skills (1 skill(s))', $result['output']);
    }

    public function testStatusRespectsConfigOption(): void
    {
        file_put_contents($this->root . '/.agent-loop.init.json', json_encode([
            'version' => 1,
            'paths' => [
                'skills_root' => 'config-skills',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runStatus(['--config=.agent-loop.init.json']);

        self::assertStringContainsString('[OK] skills-root: config-skills', $result['output']);
    }

    public function testCliSkillsRootOverridesConfig(): void
    {
        file_put_contents($this->root . '/.agent-loop.init.json', json_encode([
            'version' => 1,
            'paths' => [
                'skills_root' => 'config-skills',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runStatus(['--config=.agent-loop.init.json', '--skills-root=cli-skills']);

        self::assertStringContainsString('[OK] skills-root: cli-skills', $result['output']);
        self::assertStringNotContainsString('skills-root: config-skills', $result['output']);
    }

    public function testStatusReportsSkillCount(): void
    {
        mkdir($this->root . '/docs/agents/skills/alpha', 0o775, true);
        mkdir($this->root . '/docs/agents/skills/beta', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/alpha/SKILL.md', "# Alpha\n");
        file_put_contents($this->root . '/docs/agents/skills/beta/SKILL.md', "# Beta\n");

        $result = $this->runStatus([]);

        self::assertStringContainsString('[OK] skills-root: docs/agents/skills (2 skill(s))', $result['output']);
    }

    public function testStatusReportsSubagentCount(): void
    {
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n");

        $result = $this->runStatus([]);

        self::assertStringContainsString('[OK] subagents-root: docs/agents/subagents (1 file(s))', $result['output']);
    }

    public function testStatusReportsHooksJsonAndScriptCount(): void
    {
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', "{}\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/preflight.php', "<?php\n");

        $result = $this->runStatus([]);

        self::assertStringContainsString('[OK] hooks-root: docs/agents/codex-hooks (hooks.json: yes, scripts: 1)', $result['output']);
    }

    public function testStatusPrintsAgentAliasesIncludingGeminiToAntigravity(): void
    {
        $result = $this->runStatus([]);

        self::assertStringContainsString('[INFO] alias openai-codex -> codex', $result['output']);
        self::assertStringContainsString('[INFO] alias claude-code -> claude', $result['output']);
        self::assertStringContainsString('[INFO] alias github-copilot -> copilot', $result['output']);
        self::assertStringContainsString('[INFO] alias agy -> antigravity', $result['output']);
        self::assertStringContainsString('[INFO] alias google-antigravity -> antigravity', $result['output']);
        self::assertStringContainsString('[INFO] alias gemini -> antigravity', $result['output']);
        self::assertStringContainsString('[INFO] alias gemini-cli -> antigravity', $result['output']);
    }

    public function testStatusReportsNoManifestWhenTargetMissing(): void
    {
        $result = $this->runStatus([]);

        self::assertStringContainsString('[INFO] codex skills: no manifest at ' . $this->root . '/.codex/skills/.agent-loop-manifest.json', $result['output']);
    }

    public function testStatusReportsManifestFoundWhenManifestExists(): void
    {
        mkdir($this->root . '/.codex/skills', 0o775, true);
        file_put_contents($this->root . '/.codex/skills/.agent-loop-manifest.json', json_encode([
            'version' => 1,
            'kind' => 'skills',
            'agent' => 'codex',
            'entries' => ['demo-skill'],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runStatus([]);

        self::assertStringContainsString('[OK] codex skills: manifest found (1 managed entrie(s))', $result['output']);
    }

    public function testStatusReportsStaleManagedEntries(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");

        mkdir($this->root . '/.codex/skills', 0o775, true);
        file_put_contents($this->root . '/.codex/skills/.agent-loop-manifest.json', json_encode([
            'version' => 1,
            'kind' => 'skills',
            'agent' => 'codex',
            'entries' => ['demo-skill', 'old-stale-skill'],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runStatus([]);

        self::assertStringContainsString('[WARN] codex skills: stale managed entries: old-stale-skill', $result['output']);
    }

    public function testStatusReportsNoStaleManagedEntriesWhenInSync(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# Demo\n");

        mkdir($this->root . '/.codex/skills', 0o775, true);
        file_put_contents($this->root . '/.codex/skills/.agent-loop-manifest.json', json_encode([
            'version' => 1,
            'kind' => 'skills',
            'agent' => 'codex',
            'entries' => ['demo-skill'],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runStatus([]);

        self::assertStringContainsString('[OK] codex skills: no stale managed entries', $result['output']);
    }

    public function testStatusWarnsButContinuesOnInvalidManifest(): void
    {
        mkdir($this->root . '/.codex/skills', 0o775, true);
        file_put_contents($this->root . '/.codex/skills/.agent-loop-manifest.json', '{invalid');

        $result = $this->runStatus([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[WARN] codex skills:', $result['output']);
        self::assertStringContainsString('agent-loop init status', $result['output']);
    }

    public function testUnknownStatusOptionExitsOne(): void
    {
        $result = $this->runStatus(['--bogus=value']);

        self::assertSame(1, $result['exit']);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runStatus(array $tokens): array
    {
        $command = new InitStatusCommand($this->root);

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
