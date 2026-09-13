<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\RepositorySetupService;

/** @internal */
final class RepositorySetupSkillStateScopeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-skill-state-' . bin2hex(random_bytes(6));
        $skillRoot = $this->root . '/fixture/skills/selected-skill';
        if (!mkdir($skillRoot, 0o775, true) && !is_dir($skillRoot)) {
            throw new RuntimeException('Unable to create selected-skill fixture.');
        }
        file_put_contents($skillRoot . '/SKILL.md', "# Selected skill\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUnselectedFileUnderSkillsRootDoesNotInvalidateReviewedInstallPlan(): void
    {
        $paths = new AgentAssetSourcePaths(
            $this->root,
            'fixture/skills',
            'fixture/subagents',
            'fixture/hooks',
            'fixture/tools',
            'fixture/claude-hooks',
        );
        $service = new RepositorySetupService($this->root);
        $plan = $service->planInstall('codex', false, $paths);

        $unselected = $this->root . '/fixture/skills/not-a-skill';
        if (!mkdir($unselected, 0o775, true) && !is_dir($unselected)) {
            throw new RuntimeException('Unable to create unselected source fixture.');
        }
        file_put_contents($unselected . '/README.md', "not a managed skill\n");

        $result = $service->install($plan, $plan->expectedState->value, $paths);

        self::assertTrue($result->succeeded);
        self::assertFileExists($this->root . '/.codex/skills/selected-skill/SKILL.md');
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
