<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Init\InitStatusCommand;
use voku\AgentLoop\Init\InitSyncSkillsCommand;

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
        $this->backupEnv([
            'CODEX_HOME',
            'CODEX_SKILLS_DIR',
            'CODEX_AGENTS_DIR',
            'CLAUDE_SKILLS_DIR',
            'CLAUDE_AGENTS_DIR',
            'CLAUDE_CONFIG_DIR',
            'COPILOT_SKILLS_DIR',
            'ANTIGRAVITY_SKILLS_DIR',
            'COPILOT_AGENTS_DIR',
            'ANTIGRAVITY_AGENTS_DIR',
        ]);
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
        self::assertStringContainsString('[INFO] codex subagents: no manifest at ' . $this->root . '/.codex/agents/.agent-loop-manifest.json', $result['output']);
        self::assertStringContainsString('[INFO] claude subagents: no manifest at ' . $this->root . '/.claude/agents/.agent-loop-manifest.json', $result['output']);
        self::assertStringContainsString('[INFO] claude hooks: no manifest at ' . $this->root . '/.claude/.agent-loop-manifest.json', $result['output']);
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
        self::assertStringContainsString('drift evidence unavailable for legacy managed entries', $result['output']);
    }

    public function testStatusReportsCodexSubagentManifestAndStaleEntries(): void
    {
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "---\nname: reviewer\ndescription: Review things\n---\n\n# Reviewer\n");

        mkdir($this->root . '/.codex/agents', 0o775, true);
        file_put_contents($this->root . '/.codex/agents/.agent-loop-manifest.json', json_encode([
            'version' => 1,
            'kind' => 'subagents',
            'agent' => 'codex',
            'entries' => ['reviewer.toml', 'old-role.toml'],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runStatus([]);

        self::assertStringContainsString('[OK] codex subagents: manifest found (2 managed entrie(s))', $result['output']);
        self::assertStringContainsString('[WARN] codex subagents: stale managed entries: old-role.toml', $result['output']);
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

    public function testStatusDistinguishesCurrentLocalModificationAndStaleSource(): void
    {
        $source = $this->root . '/docs/agents/skills/demo-skill/SKILL.md';
        $target = $this->root . '/.codex/skills/demo-skill/SKILL.md';
        mkdir(dirname($source), 0o775, true);
        file_put_contents($source, "# Demo\n");

        $sync = $this->runSyncSkills(['--agent=codex']);
        self::assertSame(0, $sync['exit'], $sync['output']);
        self::assertStringContainsString('[OK] codex skills: current: demo-skill', $this->runStatus([])['output']);

        file_put_contents($target, "# Local edit\n");
        self::assertStringContainsString('[WARN] codex skills: locally modified: demo-skill', $this->runStatus([])['output']);

        $repair = $this->runSyncSkills(['--agent=codex']);
        self::assertSame(0, $repair['exit'], $repair['output']);
        self::assertStringContainsString('[OK] codex skills: current: demo-skill', $this->runStatus([])['output']);

        file_put_contents($source, "# Upgraded source\n");
        self::assertStringContainsString('[WARN] codex skills: stale: demo-skill', $this->runStatus([])['output']);
    }

    public function testStatusKeepsAdoptedAssetProjectOwned(): void
    {
        $source = $this->root . '/docs/agents/skills/demo-skill/SKILL.md';
        $target = $this->root . '/.codex/skills/demo-skill/SKILL.md';
        mkdir(dirname($source), 0o775, true);
        mkdir(dirname($target), 0o775, true);
        file_put_contents($source, "# Canonical\n");
        file_put_contents($target, "# Existing project-owned content\n");

        $sync = $this->runSyncSkills(['--agent=codex', '--adopt-existing']);
        self::assertSame(0, $sync['exit'], $sync['output']);

        $output = $this->runStatus([])['output'];
        self::assertStringContainsString('[INFO] codex skills: unmanaged/project-owned: demo-skill', $output);
        self::assertStringNotContainsString('[OK] codex skills: current: demo-skill', $output);
    }

    public function testStatusReportsCapabilityMismatchAndResyncRepairsBaseline(): void
    {
        $source = $this->root . '/docs/agents/skills/demo-skill/SKILL.md';
        mkdir(dirname($source), 0o775, true);
        file_put_contents($source, "# Demo\n");

        $sync = $this->runSyncSkills(['--agent=codex']);
        self::assertSame(0, $sync['exit'], $sync['output']);

        $manifestPath = $this->root . '/.codex/skills/.agent-loop-manifest.json';
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $manifest['required_capabilities'] = ['future-runtime-capability'];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

        self::assertStringContainsString(
            '[WARN] codex skills: incompatible with installed runtime capabilities: demo-skill',
            $this->runStatus([])['output'],
        );

        $repair = $this->runSyncSkills(['--agent=codex']);
        self::assertSame(0, $repair['exit'], $repair['output']);
        $output = $this->runStatus([])['output'];
        self::assertStringContainsString('[OK] codex skills: current: demo-skill', $output);
        self::assertStringNotContainsString('incompatible with installed runtime capabilities: demo-skill', $output);
    }

    public function testStatusReportsExistingUnmanagedAssetWithoutManifestAsProjectOwned(): void
    {
        $source = $this->root . '/docs/agents/skills/demo-skill/SKILL.md';
        $target = $this->root . '/.codex/skills/demo-skill/SKILL.md';
        mkdir(dirname($source), 0o775, true);
        mkdir(dirname($target), 0o775, true);
        file_put_contents($source, "# Canonical\n");
        file_put_contents($target, "# Project owned\n");

        $output = $this->runStatus([])['output'];

        self::assertStringContainsString('[INFO] codex skills: unmanaged/project-owned: demo-skill', $output);
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

    public function testStatusDoesNotFlagHooksAsStaleWhenHooksJsonIsInvalid(): void
    {
        mkdir($this->root . '/docs/agents/codex-hooks', 0o775, true);
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', '{invalid-json');

        mkdir($this->root . '/.codex', 0o775, true);
        file_put_contents($this->root . '/.codex/.agent-loop-manifest.json', json_encode([
            'version' => 1,
            'kind' => 'hooks',
            'agent' => 'codex',
            'entries' => ['hooks.json', 'hooks/session_context.php'],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runStatus([]);

        self::assertSame(0, $result['exit']);
        self::assertStringNotContainsString('[WARN] codex hooks: stale managed entries:', $result['output']);
        self::assertStringContainsString('[INFO] codex hooks: stale entries not checked (source invalid)', $result['output']);
    }

    public function testUnknownStatusOptionExitsOne(): void
    {
        $result = $this->runStatus(['--bogus=value']);

        self::assertSame(1, $result['exit']);
    }

    public function testFreshRepositoryIsReportedAsUnactivatedInsteadOfHealthy(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo-skill/SKILL.md', "# demo\n");

        $result = $this->runStatus([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('Activation:', $result['output']);
        self::assertStringContainsString(
            '[WARN] Host skills: not projected for any host, so no running agent can read them; run vendor/bin/agent-loop init install-assets --agent=',
            $result['output'],
        );
        self::assertStringContainsString(
            "Next:\n  vendor/bin/agent-loop init install-assets --agent=",
            $result['output'],
        );
    }

    public function testProjectedHostIsReportedAsProjectedWithoutClaimingConsumption(): void
    {
        $skillsTarget = $this->root . '/.claude/skills';
        mkdir($skillsTarget, 0o775, true);
        putenv('CLAUDE_SKILLS_DIR=' . $skillsTarget);
        file_put_contents(
            $skillsTarget . '/.agent-loop-manifest.json',
            json_encode(['version' => 1, 'kind' => 'skills', 'agent' => 'claude', 'entries' => ['demo-skill']]),
        );

        $output = $this->runStatus([])['output'];

        self::assertStringContainsString('[OK] Host skills: projected for claude', $output);
        self::assertStringNotContainsString('init install-assets --agent=', $output);
    }

    public function testReExportedRecallSkillsAreNotReportedAsStaleRightAfterInstalling(): void
    {
        $skillsTarget = $this->root . '/.claude/skills';
        mkdir($skillsTarget, 0o775, true);
        putenv('CLAUDE_SKILLS_DIR=' . $skillsTarget);
        file_put_contents(
            $skillsTarget . '/.agent-loop-manifest.json',
            json_encode([
                'version' => 1,
                'kind' => 'skills',
                'agent' => 'claude',
                'entries' => ['agent-recall-consumer'],
            ]),
        );

        $output = $this->runStatus([])['output'];

        self::assertStringContainsString('[OK] claude skills: no stale managed entries', $output);
        self::assertStringNotContainsString('stale managed entries: agent-recall-consumer', $output);
    }

    public function testLocalGitIntegrationIsReportedByTheEntryCommandItself(): void
    {
        $runner = new ProcessRunner($this->root);
        if ($runner->run(['git', '--version'])['exit_code'] !== 0) {
            self::markTestSkipped('git is not available.');
        }

        $runner->mustRun(['git', 'init', '--quiet']);
        mkdir($this->root . '/.agent-loop', 0o775, true);
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{}\n");

        $output = $this->runStatus([])['output'];

        self::assertStringContainsString('[WARN] Git hooks: project policy exists but core.hooksPath is unset', $output);
        self::assertStringContainsString('  vendor/bin/agent-loop init sync-githooks --hooks-dir=.githooks', $output);
    }

    /**
     * @param list<string> $tokens
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
