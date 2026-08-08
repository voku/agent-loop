<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;

/** @internal */
final class AgentDisciplineContextBudgetTest extends TestCase
{
    public function testCanonicalDisciplineSurvivesMaximumBoundedClaudeResumeHint(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-discipline-budget-' . bin2hex(random_bytes(6));
        $skillDirectory = $root . '/.claude/skills/agent-loop-discipline';
        self::assertTrue(mkdir($skillDirectory, 0o775, true));

        $skill = file_get_contents(dirname(__DIR__) . '/docs/agents/skills/agent-loop-discipline/SKILL.md');
        self::assertIsString($skill);
        self::assertLessThanOrEqual(8_000, strlen($skill), 'Canonical bootstrap skill must leave room for the bounded resume hint.');
        self::assertNotFalse(file_put_contents($skillDirectory . '/SKILL.md', $skill));

        for ($index = 0; $index < 5; ++$index) {
            $prefix = sprintf('TASK-%d-', $index);
            $taskId = $prefix . str_repeat(chr(65 + $index), 128 - strlen($prefix));
            $runDirectory = $root . '/.agent-loop/runs/' . $taskId;
            self::assertTrue(mkdir($runDirectory, 0o775, true));
            self::assertNotFalse(file_put_contents(
                $runDirectory . '/manifest.json',
                json_encode([
                    'task_id' => $taskId,
                    'state' => 'incomplete',
                    'next_action' => str_repeat('UNTRUSTED-', 100),
                ], JSON_THROW_ON_ERROR),
            ));
        }

        try {
            $output = (new AgentDisciplineHook($root))->claudeContextOutput(
                'SessionStart',
                json_encode(['hook_event_name' => 'SessionStart', 'source' => 'resume'], JSON_THROW_ON_ERROR),
            );
            $context = $output['hookSpecificOutput']['additionalContext'];

            self::assertLessThanOrEqual(9_500, strlen($context));
            self::assertStringContainsString('Agent Loop Resume Hint', $context);
            self::assertStringContainsString('Engineering Skill Routing', $context);
            self::assertStringContainsString('do not manufacture follow-up work', $context);
            self::assertStringNotContainsString('Minimal Implementation Ladder', $context);
            self::assertStringNotContainsString('UNTRUSTED-', $context);
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($directory);
    }
}
