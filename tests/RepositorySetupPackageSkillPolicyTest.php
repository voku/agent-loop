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

        $configRoot = $this->root . '/.agent-loop';
        if (!mkdir($configRoot, 0o775, true) && !is_dir($configRoot)) {
            throw new RuntimeException('Unable to create init config fixture.');
        }
        file_put_contents(
            $configRoot . '/init.json',
            json_encode([
                'package_skills' => false,
                'paths' => [
                    'skills_root' => 'custom-skills',
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
