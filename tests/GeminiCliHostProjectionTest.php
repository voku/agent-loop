<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\HostCapability;
use voku\AgentLoop\Init\HostCapabilityMatrix;
use voku\AgentLoop\Init\HostCapabilityStatus;
use voku\AgentLoop\Init\InitAgent;
use voku\AgentLoop\Init\InitSyncInstructionsCommand;
use voku\AgentLoop\Init\InitSyncSkillsCommand;
use voku\AgentLoop\Init\InitSyncSubagentsCommand;

final class GeminiCliHostProjectionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-gemini-host-' . bin2hex(random_bytes(4));
        if (!mkdir($this->root, 0o775, true) && !is_dir($this->root)) {
            throw new RuntimeException('Unable to create test root: ' . $this->root);
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testGeminiCliResolvesToItsOwnCanonicalHost(): void
    {
        foreach (['gemini', 'gemini-cli'] as $requested) {
            $agent = InitAgent::parse($requested, InitAgent::canonicalNames());

            self::assertSame('gemini', $agent->canonicalName());
            self::assertSame([], $agent->messages());
        }

        $antigravity = InitAgent::parse('antigravity', InitAgent::canonicalNames());
        self::assertSame('antigravity', $antigravity->canonicalName());
    }

    public function testGeminiHasSeparatePortableProjectionMechanisms(): void
    {
        self::assertSame(
            HostCapabilityStatus::Supported,
            HostCapabilityMatrix::status('gemini', HostCapability::SkillProjection),
        );
        self::assertSame(
            HostCapabilityStatus::Supported,
            HostCapabilityMatrix::status('gemini', HostCapability::SubagentProjection),
        );
    }

    public function testGeminiProjectsSkillsSubagentsAndInstructionsUnderGeminiPaths(): void
    {
        $skillsRoot = $this->root . '/fixtures/skills';
        $subagentsRoot = $this->root . '/fixtures/subagents';
        $this->writeFile(
            $skillsRoot . '/sample/SKILL.md',
            "---\nname: sample\ndescription: Sample skill\n---\n\nUse the sample skill.\n",
        );
        $this->writeFile(
            $subagentsRoot . '/sample-agent.md',
            "---\nname: sample-agent\ndescription: Sample agent\n---\n\nInspect the repository.\n",
        );

        self::assertSame(0, $this->quiet(fn (): int => (new InitSyncSkillsCommand($this->root))->run([
            '--agent=gemini',
            '--skills-root=' . $skillsRoot,
        ])));
        self::assertFileExists($this->root . '/.gemini/skills/sample/SKILL.md');
        self::assertDirectoryDoesNotExist($this->root . '/.agents/skills/sample');

        self::assertSame(0, $this->quiet(fn (): int => (new InitSyncSubagentsCommand($this->root))->run([
            '--agent=gemini',
            '--subagents-root=' . $subagentsRoot,
        ])));
        $geminiAgent = $this->root . '/.gemini/agents/sample-agent.md';
        self::assertFileExists($geminiAgent);
        self::assertFileDoesNotExist($this->root . '/.agents/agents/sample-agent.md');
        $projectedAgent = file_get_contents($geminiAgent);
        self::assertIsString($projectedAgent);
        self::assertStringContainsString("kind: \"local\"", $projectedAgent);
        self::assertStringContainsString('max_turns: 12', $projectedAgent);
        self::assertStringContainsString('temperature: 0.2', $projectedAgent);

        self::assertSame(0, $this->quiet(fn (): int => (new InitSyncInstructionsCommand($this->root))->run([
            '--agent=gemini',
        ])));
        $geminiInstructions = file_get_contents($this->root . '/GEMINI.md');
        self::assertIsString($geminiInstructions);
        self::assertStringContainsString('@./AGENTS.md', $geminiInstructions);
    }

    public function testAntigravityKeepsItsExistingProjectionPaths(): void
    {
        $skillsRoot = $this->root . '/fixtures/skills';
        $this->writeFile(
            $skillsRoot . '/sample/SKILL.md',
            "---\nname: sample\ndescription: Sample skill\n---\n\nUse the sample skill.\n",
        );

        self::assertSame(0, $this->quiet(fn (): int => (new InitSyncSkillsCommand($this->root))->run([
            '--agent=antigravity',
            '--skills-root=' . $skillsRoot,
        ])));
        self::assertFileExists($this->root . '/.agents/skills/sample/SKILL.md');
        self::assertDirectoryDoesNotExist($this->root . '/.gemini/skills/sample');
    }

    private function writeFile(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create test directory: ' . $directory);
        }
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException('Unable to write test file: ' . $path);
        }
    }

    /** @param callable(): int $callback */
    private function quiet(callable $callback): int
    {
        ob_start();
        try {
            return $callback();
        } finally {
            ob_end_clean();
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                if (!rmdir($path)) {
                    throw new RuntimeException('Unable to remove test directory: ' . $path);
                }
            } elseif (!unlink($path)) {
                throw new RuntimeException('Unable to remove test file: ' . $path);
            }
        }

        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to remove test root: ' . $directory);
        }
    }
}
