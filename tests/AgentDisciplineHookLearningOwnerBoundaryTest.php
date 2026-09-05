<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\AgentGuidance\AgentDisciplineHook;

final class AgentDisciplineHookLearningOwnerBoundaryTest extends TestCase
{
    public function testLearningBacklogDiscoveryStaysBehindLearningOwnerApi(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/AgentGuidance/AgentDisciplineHook.php');

        self::assertIsString($source);
        self::assertStringContainsString('(new FindingRepository())->loadValidated($root)', $source);
        self::assertStringNotContainsString("'/findings/validated'", $source);
    }

    public function testMissingLearningTreeStaysSilentThroughOwnerRepository(): void
    {
        $root = sys_get_temp_dir() . '/agent-loop-learning-owner-boundary-' . bin2hex(random_bytes(6));
        $skillDirectory = $root . '/.codex/skills/agent-loop-discipline';
        self::assertTrue(mkdir($skillDirectory, 0o775, true));
        self::assertNotFalse(file_put_contents(
            $skillDirectory . '/SKILL.md',
            "---\nname: agent-loop-discipline\n---\nEngineering Skill Routing\n",
        ));

        try {
            $output = (new AgentDisciplineHook($root))->contextOutput('SessionStart', json_encode([
                'hook_event_name' => 'SessionStart',
            ], JSON_THROW_ON_ERROR));

            self::assertStringNotContainsString(
                'Agent Loop Learning Backlog',
                $output['hookSpecificOutput']['additionalContext'],
            );
        } finally {
            $this->removeTree($root);
        }
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($root);
    }
}
