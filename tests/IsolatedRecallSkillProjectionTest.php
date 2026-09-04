<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\InitSyncSkillsCommand;

/** @internal */
final class IsolatedRecallSkillProjectionTest extends TestCase
{
    private string $root;
    private string|false $claudeSkillsDir;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-isolated-recall-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/recall-skills/agent-recall-consumer', 0o775, true);
        mkdir($this->root . '/tools/agent-loop/vendor/bin', 0o775, true);
        file_put_contents($this->root . '/tools/agent-loop/vendor/bin/agent-loop', "#!/usr/bin/env php\n");
        file_put_contents(
            $this->root . '/recall-skills/agent-recall-consumer/SKILL.md',
            <<<'MD'
# Recall consumer

- `vendor/bin/agent-loop recall compile`
- `vendor/bin/agent-recall-compiler compile`
- `vendor/voku/agent-recall-compiler/resources/skills/agent-recall-consumer/operating-prompts.json`
MD,
        );

        $this->claudeSkillsDir = getenv('CLAUDE_SKILLS_DIR');
        putenv('CLAUDE_SKILLS_DIR=' . $this->root . '/.claude/skills');
    }

    protected function tearDown(): void
    {
        $this->claudeSkillsDir === false
            ? putenv('CLAUDE_SKILLS_DIR')
            : putenv('CLAUDE_SKILLS_DIR=' . $this->claudeSkillsDir);

        if (!is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    public function testRecallSkillUsesTheSameIsolatedComposerVendorRootAsAgentLoop(): void
    {
        ob_start();
        $exit = (new InitSyncSkillsCommand($this->root))->run([
            '--agent=claude',
            '--skills-root=recall-skills',
        ]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        $projected = file_get_contents($this->root . '/.claude/skills/agent-recall-consumer/SKILL.md');
        self::assertIsString($projected);
        self::assertStringContainsString('tools/agent-loop/vendor/bin/agent-loop recall compile', $projected);
        self::assertStringContainsString('tools/agent-loop/vendor/bin/agent-recall-compiler compile', $projected);
        self::assertStringContainsString(
            'tools/agent-loop/vendor/voku/agent-recall-compiler/resources/skills/agent-recall-consumer/operating-prompts.json',
            $projected,
        );
        self::assertStringNotContainsString('`vendor/bin/', $projected);
        self::assertStringNotContainsString('`vendor/voku/', $projected);
    }
}
