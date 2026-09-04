<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitSyncSkillsCommand;
use voku\AgentLoop\Init\InitSyncSubagentsCommand;
use voku\AgentLoop\Init\RepositoryActivation;

/**
 * @internal
 */
final class IsolatedToolActivationTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-isolated-tool-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        $this->backupEnv(['CLAUDE_SKILLS_DIR', 'CLAUDE_AGENTS_DIR']);
        putenv('CLAUDE_SKILLS_DIR=' . $this->root . '/.claude/skills');
        putenv('CLAUDE_AGENTS_DIR=' . $this->root . '/.claude/agents');

        file_put_contents(
            $this->root . '/composer.json',
            json_encode(['name' => 'acme/legacy-host'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );
        mkdir($this->root . '/vendor', 0o775, true);
        file_put_contents($this->root . '/vendor/autoload.php', "<?php\nreturn true;\n");

        mkdir($this->root . '/tools/agent-loop/vendor/bin', 0o775, true);
        file_put_contents($this->root . '/tools/agent-loop/vendor/bin/agent-loop', "#!/usr/bin/env php\n");
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

    public function testRepositoryActivationResolvesTheUniqueIsolatedToolBinary(): void
    {
        $activation = new RepositoryActivation($this->root);

        self::assertSame('tools/agent-loop/vendor/bin/agent-loop', $activation->cliPath());
        self::assertTrue($activation->cliAvailable());
        self::assertSame(
            '[OK] CLI: tools/agent-loop/vendor/bin/agent-loop',
            $activation->cliCheck()->render(),
        );
    }

    public function testSkillProjectionUsesTheResolvedToolBinary(): void
    {
        mkdir($this->root . '/resources/skills/demo-skill', 0o775, true);
        file_put_contents(
            $this->root . '/resources/skills/demo-skill/SKILL.md',
            "# Demo\n\nRun `vendor/bin/agent-loop workflow status DEMO-1`.\n",
        );

        ob_start();
        $exit = (new InitSyncSkillsCommand($this->root))->run(['--agent=claude']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        $projected = file_get_contents($this->root . '/.claude/skills/demo-skill/SKILL.md');
        self::assertIsString($projected);
        self::assertStringContainsString('tools/agent-loop/vendor/bin/agent-loop workflow status DEMO-1', $projected);
        self::assertStringNotContainsString('`vendor/bin/agent-loop ', $projected);
    }

    public function testSubagentProjectionUsesTheResolvedToolBinary(): void
    {
        mkdir($this->root . '/resources/subagents', 0o775, true);
        file_put_contents(
            $this->root . '/resources/subagents/demo-role.md',
            "---\nname: demo-role\ndescription: Demo role.\n---\n\nRun `vendor/bin/agent-loop map discover --limit=10`.\n",
        );

        ob_start();
        $exit = (new InitSyncSubagentsCommand($this->root))->run(['--agent=claude']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        $projected = file_get_contents($this->root . '/.claude/agents/demo-role.md');
        self::assertIsString($projected);
        self::assertStringContainsString('tools/agent-loop/vendor/bin/agent-loop map discover --limit=10', $projected);
        self::assertStringNotContainsString('`vendor/bin/agent-loop ', $projected);
    }

    /** @param list<string> $names */
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
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}
