<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitDoctorCommand;

/**
 * @internal
 */
final class InitDoctorCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-doctor-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testDoctorReportsPhpAndMissingComposerAndMissingMakefile(): void
    {
        $result = $this->runDoctor([]);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] PHP:', $result['output']);
        self::assertStringContainsString('[WARN] Composer: composer.json not found', $result['output']);
        self::assertStringContainsString('[WARN] Make: no Makefile found', $result['output']);
    }

    public function testDoctorReportsValidComposerAndScriptsFound(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode([
            'scripts' => [
                'ci' => 'composer ci',
                'test' => 'phpunit',
                'phpstan' => 'phpstan analyse',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runDoctor([]);

        self::assertStringContainsString('[OK] Composer: composer.json found', $result['output']);
        self::assertStringContainsString('[OK] Composer scripts: found ci, test, phpstan', $result['output']);
    }

    public function testDoctorWarnsOnInvalidComposerJson(): void
    {
        file_put_contents($this->root . '/composer.json', '{invalid');

        $result = $this->runDoctor([]);

        self::assertStringContainsString('[WARN] Composer: invalid composer.json', $result['output']);
    }

    public function testDoctorReportsMigrationCompatibleMakeTargets(): void
    {
        file_put_contents($this->root . '/Makefile', ".PHONY: validate_agent_skills install_agent_skills\nvalidate_agent_skills:\n\t@true\ninstall_agent_skills:\n\t@true\n");

        $result = $this->runDoctor([]);

        self::assertStringContainsString('[OK] Make: Makefile found', $result['output']);
        self::assertStringContainsString('[OK] Make agent assets: found validate_agent_skills, install_agent_skills', $result['output']);
    }

    public function testDoctorReportsDefaultResolvedSourcePathsAndMissingSkills(): void
    {
        $result = $this->runDoctor([]);

        self::assertStringContainsString('[INFO] skills-root: docs/agents/skills', $result['output']);
        self::assertStringContainsString('[INFO] subagents-root: docs/agents/subagents', $result['output']);
        self::assertStringContainsString('[INFO] hooks-root: docs/agents/codex-hooks', $result['output']);
        self::assertStringContainsString('[INFO] tools-root: docs/agents/tools', $result['output']);
        self::assertStringContainsString('[WARN] Skills: no repo-managed skills found under docs/agents/skills/*/SKILL.md', $result['output']);
    }

    public function testDoctorReportsCustomSkillsRoot(): void
    {
        mkdir($this->root . '/custom/skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/custom/skills/demo-skill/SKILL.md', "# Demo\n");

        $result = $this->runDoctor(['--skills-root=custom/skills']);

        self::assertStringContainsString('[INFO] skills-root: custom/skills', $result['output']);
        self::assertStringContainsString('[OK] Skills: 1 repo-managed skill file(s) found under custom/skills', $result['output']);
    }

    public function testDoctorReportsConfigProvidedPaths(): void
    {
        mkdir($this->root . '/config-skills/demo-skill', 0o775, true);
        file_put_contents($this->root . '/config-skills/demo-skill/SKILL.md', "# Demo\n");
        file_put_contents($this->root . '/.agent-loop.init.json', json_encode([
            'version' => 1,
            'paths' => [
                'skills_root' => 'config-skills',
                'subagents_root' => 'config-subagents',
                'codex_hooks_root' => 'config-hooks',
                'tools_root' => 'config-tools',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runDoctor(['--config=.agent-loop.init.json']);

        self::assertStringContainsString('[INFO] skills-root: config-skills', $result['output']);
        self::assertStringContainsString('[INFO] subagents-root: config-subagents', $result['output']);
        self::assertStringContainsString('[INFO] hooks-root: config-hooks', $result['output']);
        self::assertStringContainsString('[INFO] tools-root: config-tools', $result['output']);
    }

    public function testDoctorWarnsOnInvalidConfigJson(): void
    {
        file_put_contents($this->root . '/.agent-loop.init.json', '{invalid');

        $result = $this->runDoctor(['--config=.agent-loop.init.json']);

        self::assertStringContainsString('[WARN] init config: invalid JSON', $result['output']);
    }

    public function testDoctorReportsSubagentsHooksAndToolsAsReserved(): void
    {
        mkdir($this->root . '/docs/agents/subagents', 0o775, true);
        mkdir($this->root . '/docs/agents/codex-hooks/hooks', 0o775, true);
        mkdir($this->root . '/docs/agents/tools', 0o775, true);
        file_put_contents($this->root . '/docs/agents/subagents/reviewer.md', "# Reviewer\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks.json', "{}\n");
        file_put_contents($this->root . '/docs/agents/codex-hooks/hooks/preflight.php', "<?php\n");

        $result = $this->runDoctor([]);

        self::assertStringContainsString('[INFO] Subagents: detected 1 candidate file(s), validation reserved', $result['output']);
        self::assertStringContainsString('[INFO] Codex hooks: detected hooks.json and 1 hook file(s), validation reserved', $result['output']);
        self::assertStringContainsString('[INFO] Tools: tools directory found', $result['output']);
        self::assertStringContainsString('[OK] Workflow: init diagnostics do not affect workflow close', $result['output']);
    }

    public function testDoctorWritesNoFiles(): void
    {
        $before = $this->listFiles($this->root);

        $result = $this->runDoctor([]);

        self::assertSame(0, $result['exit']);
        self::assertSame($before, $this->listFiles($this->root));
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runDoctor(array $tokens): array
    {
        $command = new InitDoctorCommand($this->root);

        ob_start();
        $exit = $command->run($tokens);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
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
