<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\ManagedAssetKind;
use voku\AgentLoop\Init\ManagedAssetOperation;
use voku\AgentLoop\Init\RepositorySetupService;

final class RepositorySetupPackageSkillPolicyTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-package-policy-' . bin2hex(random_bytes(6));
        $skillRoot = $this->root . '/custom-skills/repository-skill';
        if (!mkdir($skillRoot, 0o775, true) && !is_dir($skillRoot)) {
            throw new RuntimeException('Unable to create repository skill fixture.');
        }
        file_put_contents($skillRoot . '/SKILL.md', "# Repository skill\n");

        $subagentRoot = $this->root . '/custom-subagents';
        if (!mkdir($subagentRoot, 0o775, true) && !is_dir($subagentRoot)) {
            throw new RuntimeException('Unable to create repository subagent fixture.');
        }
        file_put_contents(
            $subagentRoot . '/repository-subagent.md',
            "---\nname: repository-subagent\ndescription: Repository subagent.\n---\n\nSubagent body.\n",
        );

        $configRoot = $this->root . '/.agent-loop';
        if (!mkdir($configRoot, 0o775, true) && !is_dir($configRoot)) {
            throw new RuntimeException('Unable to create init config fixture.');
        }
        file_put_contents(
            $configRoot . '/init.json',
            json_encode([
                'package_skills' => false,
                'package_subagents' => false,
                'paths' => [
                    'skills_root' => 'custom-skills',
                    'subagents_root' => 'custom-subagents',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        );
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTypedInstallPlanHonorsConfiguredPackageSkillExclusion(): void
    {
        $plan = (new RepositorySetupService($this->root))->planInstall('codex');
        $skillEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $plan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SKILLS,
            ),
        ));

        self::assertContains('repository-skill', $skillEntries);
        self::assertNotContains('agent-loop-discipline', $skillEntries);
        self::assertNotContains('agent-learning-consumer', $skillEntries);
        self::assertNotContains('agent-recall-consumer', $skillEntries);
    }

    public function testTypedInstallPlanHonorsConfiguredPackageSubagentExclusion(): void
    {
        $plan = (new RepositorySetupService($this->root))->planInstall('codex');
        $subagentEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $plan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SUBAGENTS,
            ),
        ));

        self::assertContains('repository-subagent.toml', $subagentEntries);
        self::assertNotContains('agent-loop-investigator.toml', $subagentEntries);
        self::assertNotContains('agent-loop-code-reviewer.toml', $subagentEntries);
    }

    public function testTypedInstallPlanIncludesPackageAssetsByDefault(): void
    {
        file_put_contents(
            $this->root . '/.agent-loop/init.json',
            json_encode([
                'paths' => [
                    'skills_root' => 'custom-skills',
                    'subagents_root' => 'custom-subagents',
                ],
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n",
        );

        $plan = (new RepositorySetupService($this->root))->planInstall('codex');
        $skillEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $plan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SKILLS,
            ),
        ));
        $subagentEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $plan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SUBAGENTS,
            ),
        ));

        self::assertContains('repository-skill', $skillEntries);
        self::assertContains('agent-loop-discipline', $skillEntries);
        self::assertContains('repository-subagent.toml', $subagentEntries);
        self::assertContains('agent-loop-investigator.toml', $subagentEntries);
    }

    public function testExplicitAssetSourcePathsPreservesCallerSemantics(): void
    {
        $explicitPaths = new \voku\AgentLoop\Init\AgentAssetSourcePaths(
            $this->root,
            'custom-skills',
            'custom-subagents',
            \voku\AgentLoop\PackageResources::hooks('codex'),
            \voku\AgentLoop\PackageResources::TOOLS,
            \voku\AgentLoop\PackageResources::hooks('claude'),
        );

        $plan = (new RepositorySetupService($this->root))->planInstall('codex', false, $explicitPaths);
        $skillEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $plan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SKILLS,
            ),
        ));
        $subagentEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $plan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SUBAGENTS,
            ),
        ));

        // Default explicit constructor includes package assets
        self::assertContains('repository-skill', $skillEntries);
        self::assertContains('agent-loop-discipline', $skillEntries);
        self::assertContains('repository-subagent.toml', $subagentEntries);
        self::assertContains('agent-loop-investigator.toml', $subagentEntries);

        // Explicitly disabled package assets via wither
        $disabledPaths = $explicitPaths->withPackageSkills(false)->withPackageSubagents(false);
        $disabledPlan = (new RepositorySetupService($this->root))->planInstall('codex', false, $disabledPaths);
        $disabledSkillEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $disabledPlan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SKILLS,
            ),
        ));
        $disabledSubagentEntries = array_values(array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            array_filter(
                $disabledPlan->operations,
                static fn (ManagedAssetOperation $operation): bool => $operation->kind === ManagedAssetKind::SUBAGENTS,
            ),
        ));

        self::assertContains('repository-skill', $disabledSkillEntries);
        self::assertNotContains('agent-loop-discipline', $disabledSkillEntries);
        self::assertContains('repository-subagent.toml', $disabledSubagentEntries);
        self::assertNotContains('agent-loop-investigator.toml', $disabledSubagentEntries);
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
