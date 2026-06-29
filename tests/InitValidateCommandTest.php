<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitValidateCommand;

/**
 * @internal
 */
final class InitValidateCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-init-validate-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testValidateSkillsWarnsAndSucceedsWhenNoSkillsExist(): void
    {
        $result = $this->runValidate(['--kind=skills']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[WARN] validate skills: no skills found under docs/agents/skills/*/SKILL.md', $result['output']);
    }

    public function testValidateSkillsSucceedsWhenSkillsAreValid(): void
    {
        mkdir($this->root . '/docs/agents/skills/phpstan-debugging', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/phpstan-debugging/SKILL.md', "# Skill\n");

        $result = $this->runValidate(['--kind=skills']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
    }

    public function testValidateSkillsFailsForEmptySkill(): void
    {
        mkdir($this->root . '/docs/agents/skills/phpstan-debugging', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/phpstan-debugging/SKILL.md', " \n\t");

        $result = $this->runValidate(['--kind=skills']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FAIL] validate skills: empty skill phpstan-debugging', $result['output']);
    }

    public function testValidateSkillsFailsForInvalidSkillName(): void
    {
        mkdir($this->root . '/docs/agents/skills/.hidden', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/.hidden/SKILL.md', "# Hidden\n");

        $result = $this->runValidate(['--kind=skills']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FAIL] validate skills: invalid skill directory name .hidden', $result['output']);
    }

    public function testValidateSkillsRespectsSkillsRootOverride(): void
    {
        mkdir($this->root . '/custom/skills/demo', 0o775, true);
        file_put_contents($this->root . '/custom/skills/demo/SKILL.md', "# Demo\n");

        $result = $this->runValidate(['--kind=skills', '--skills-root=custom/skills']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
    }

    public function testValidateSkillsRespectsConfigPath(): void
    {
        mkdir($this->root . '/config-skills/demo', 0o775, true);
        file_put_contents($this->root . '/config-skills/demo/SKILL.md', "# Demo\n");
        file_put_contents($this->root . '/.agent-loop.init.json', json_encode([
            'version' => 1,
            'paths' => [
                'skills_root' => 'config-skills',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runValidate(['--kind=skills', '--config=.agent-loop.init.json']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
    }

    public function testCliSkillsRootOverridesConfigPath(): void
    {
        mkdir($this->root . '/config-skills/demo', 0o775, true);
        mkdir($this->root . '/cli-skills/real', 0o775, true);
        file_put_contents($this->root . '/config-skills/demo/SKILL.md', "# Config\n");
        file_put_contents($this->root . '/cli-skills/real/SKILL.md', "# Cli\n");
        file_put_contents($this->root . '/.agent-loop.init.json', json_encode([
            'version' => 1,
            'paths' => [
                'skills_root' => 'config-skills',
            ],
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

        $result = $this->runValidate(['--kind=skills', '--config=.agent-loop.init.json', '--skills-root=cli-skills']);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
    }

    public function testValidateSubagentsIsReserved(): void
    {
        $result = $this->runValidate(['--kind=subagents']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('subagents validation is not implemented yet', $result['output']);
    }

    public function testValidateHooksIsReserved(): void
    {
        $result = $this->runValidate(['--kind=hooks']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('hooks validation is not implemented yet', $result['output']);
    }

    public function testValidateAllRunsSkillsThenFailsBecauseReservedChecksRemain(): void
    {
        mkdir($this->root . '/docs/agents/skills/demo', 0o775, true);
        file_put_contents($this->root . '/docs/agents/skills/demo/SKILL.md', "# Demo\n");

        $result = $this->runValidate(['--kind=all']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[OK] validate skills: 1 skill file(s) valid', $result['output']);
        self::assertStringContainsString('subagents validation is not implemented yet', $result['output']);
        self::assertStringContainsString('hooks validation is not implemented yet', $result['output']);
    }

    /**
     * @param list<string> $tokens
     *
     * @return array{exit: int, output: string}
     */
    private function runValidate(array $tokens): array
    {
        $command = new InitValidateCommand($this->root);

        ob_start();
        $exit = $command->run($tokens);
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
