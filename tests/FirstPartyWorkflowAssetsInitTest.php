<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Init\FirstPartyPackageCatalog;
use voku\AgentLoop\Init\InitSyncInstructionsCommand;
use voku\AgentLoop\Init\ManagedAssetKind;
use voku\AgentLoop\Init\ManagedAssetOperationKind;
use voku\AgentLoop\Init\RepositorySetupService;

/**
 * Verifies governed workflow asset and instruction installation/uninstallation
 * across consumer and owner repository scopes.
 *
 * @internal
 */
final class FirstPartyWorkflowAssetsInitTest extends TestCase
{
    private string $root;

    /** @var array<string, string|false> */
    private array $environment = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-workflow-assets-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        foreach (['CODEX_HOME', 'CODEX_SKILLS_DIR', 'CODEX_AGENTS_DIR'] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
        $this->removeDirectory($this->root);
    }

    public function testConsumerProjectReceivesConsumerSkillsAndNoMaintainerSkills(): void
    {
        // Simulate a consumer project (composer.json with a third-party name)
        file_put_contents($this->root . '/composer.json', json_encode(['name' => 'acme/my-project'], JSON_PRETTY_PRINT));

        $skills = FirstPartyPackageCatalog::exportableSkills($this->root);

        // Consumer skills must be present
        self::assertArrayHasKey('agent-learning-consumer', $skills);
        self::assertArrayHasKey('agent-learning-note', $skills);
        self::assertArrayHasKey('agent-hard-constraint-author', $skills);
        self::assertArrayHasKey('agent-learning-ctx-evidence', $skills);
        self::assertArrayHasKey('agent-recall-consumer', $skills);
        self::assertArrayHasKey('agent-loop-discipline', $skills);

        // Maintainer skills MUST NOT be present
        self::assertArrayNotHasKey('agent-learning-maintainer', $skills);
        self::assertArrayNotHasKey('agent-recall-compiler-maintainer', $skills);
        self::assertArrayNotHasKey('agent-session-maintainer', $skills);
    }

    public function testOwnerRepositoryReceivesItsMaintainerSkills(): void
    {
        // 1. Test voku/agent-learning owner
        $learningRoot = $this->root . '/learning-owner';
        mkdir($learningRoot, 0o775, true);
        file_put_contents($learningRoot . '/composer.json', json_encode(['name' => 'voku/agent-learning'], JSON_PRETTY_PRINT));
        $learningSkill = $learningRoot . '/resources/skills/agent-learning-maintainer';
        mkdir($learningSkill, 0o775, true);
        file_put_contents($learningSkill . '/SKILL.md', "# Learning maintainer\n");

        $learningSkills = FirstPartyPackageCatalog::exportableSkills($learningRoot);
        self::assertArrayHasKey('agent-learning-maintainer', $learningSkills);
        self::assertArrayNotHasKey('agent-recall-compiler-maintainer', $learningSkills);
        self::assertArrayNotHasKey('agent-session-maintainer', $learningSkills);

        // 2. Test voku/agent-recall-compiler owner
        $recallRoot = $this->root . '/recall-owner';
        mkdir($recallRoot, 0o775, true);
        file_put_contents($recallRoot . '/composer.json', json_encode(['name' => 'voku/agent-recall-compiler'], JSON_PRETTY_PRINT));
        $recallSkill = $recallRoot . '/resources/skills/agent-recall-compiler-maintainer';
        mkdir($recallSkill, 0o775, true);
        file_put_contents($recallSkill . '/SKILL.md', "# Recall maintainer\n");

        $recallSkills = FirstPartyPackageCatalog::exportableSkills($recallRoot);
        self::assertArrayHasKey('agent-recall-compiler-maintainer', $recallSkills);
        self::assertArrayNotHasKey('agent-learning-maintainer', $recallSkills);
        self::assertArrayNotHasKey('agent-session-maintainer', $recallSkills);

        // 3. Test voku/agent-session owner
        $sessionRoot = $this->root . '/session-owner';
        mkdir($sessionRoot, 0o775, true);
        file_put_contents($sessionRoot . '/composer.json', json_encode(['name' => 'voku/agent-session'], JSON_PRETTY_PRINT));
        $sessionSkill = $sessionRoot . '/resources/skills/agent-session-maintainer';
        mkdir($sessionSkill, 0o775, true);
        file_put_contents($sessionSkill . '/SKILL.md', "# Session maintainer\n");

        $sessionSkills = FirstPartyPackageCatalog::exportableSkills($sessionRoot);
        self::assertArrayHasKey('agent-session-maintainer', $sessionSkills);
        self::assertArrayNotHasKey('agent-learning-maintainer', $sessionSkills);
        self::assertArrayNotHasKey('agent-recall-compiler-maintainer', $sessionSkills);
    }

    public function testComposedInstructionsIncludesFragmentsForConsumer(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['name' => 'acme/consumer-app'], JSON_PRETTY_PRINT));

        $instructions = FirstPartyPackageCatalog::composedProjectInstructions($this->root);

        // Base router
        self::assertStringContainsString('agent-loop workflow router', $instructions);
        self::assertStringContainsString('agent-loop enter <task-id>', $instructions);

        // Learning fragment
        self::assertStringContainsString('Agent Learning', $instructions);
        self::assertStringContainsString('voku/agent-learning', $instructions);
        self::assertStringContainsString('agent-learning-consumer', $instructions);
    }

    public function testOwnerRepositoryOmitsItsOwnConsumerFragment(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['name' => 'voku/agent-learning'], JSON_PRETTY_PRINT));

        $fragments = FirstPartyPackageCatalog::instructionFragments($this->root);
        self::assertArrayNotHasKey('voku/agent-learning', $fragments);
    }

    public function testEndToEndInstallAndUninstallWithProjectOwnedContentPreserved(): void
    {
        file_put_contents($this->root . '/composer.json', json_encode(['name' => 'acme/gov-app'], JSON_PRETTY_PRINT));

        // Create pre-existing project-owned content in AGENTS.md
        $projectOwnedHeader = "# Custom Project Guidelines\n\n- Follow PSR-12\n- Write phpunit tests\n";
        file_put_contents($this->root . '/AGENTS.md', $projectOwnedHeader);

        $service = new RepositorySetupService($this->root);

        // 1. Plan Install
        $installPlan = $service->planInstall('codex', false);
        self::assertTrue($installPlan->mutates());

        $plannedEntries = array_map(
            static fn ($op): string => $op->entry,
            $installPlan->operations,
        );

        self::assertContains('agent-learning-consumer', $plannedEntries);
        self::assertContains('agent-recall-consumer', $plannedEntries);
        self::assertContains('AGENTS.md', $plannedEntries);
        self::assertNotContains('agent-learning-maintainer', $plannedEntries);
        self::assertNotContains('agent-session-maintainer', $plannedEntries);

        // 2. Apply Install
        $installResult = $service->install($installPlan, $installPlan->expectedState->value);
        self::assertTrue($installResult->succeeded);

        // Verify installed files on disk
        self::assertFileExists($this->root . '/.codex/skills/agent-learning-consumer/SKILL.md');
        self::assertFileExists($this->root . '/.codex/skills/agent-recall-consumer/SKILL.md');
        self::assertFileDoesNotExist($this->root . '/.codex/skills/agent-learning-maintainer/SKILL.md');
        self::assertFileDoesNotExist($this->root . '/.codex/skills/agent-session-maintainer/SKILL.md');

        // Verify AGENTS.md has both project-owned content AND managed block
        $agentsAfterInstall = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString('# Custom Project Guidelines', $agentsAfterInstall);
        self::assertStringContainsString('- Follow PSR-12', $agentsAfterInstall);
        self::assertStringContainsString(InitSyncInstructionsCommand::BEGIN_MARKER, $agentsAfterInstall);
        self::assertStringContainsString('Agent Learning', $agentsAfterInstall);
        self::assertStringContainsString(InitSyncInstructionsCommand::END_MARKER, $agentsAfterInstall);

        // 3. Plan Uninstall
        $uninstallPlan = $service->planUninstall('codex', false);
        self::assertTrue($uninstallPlan->mutates());

        $uninstalledOps = array_filter(
            $uninstallPlan->operations,
            static fn ($op): bool => $op->operation === ManagedAssetOperationKind::REMOVE,
        );
        $uninstalledEntries = array_map(
            static fn ($op): string => $op->entry,
            $uninstalledOps,
        );

        self::assertContains('agent-learning-consumer', $uninstalledEntries);
        self::assertContains('agent-recall-consumer', $uninstalledEntries);
        self::assertContains('AGENTS.md', $uninstalledEntries);

        // 4. Apply Uninstall
        $uninstallResult = $service->uninstall($uninstallPlan, $uninstallPlan->expectedState->value);
        self::assertTrue($uninstallResult->succeeded);

        // Verify skills removed
        self::assertFileDoesNotExist($this->root . '/.codex/skills/agent-learning-consumer/SKILL.md');
        self::assertFileDoesNotExist($this->root . '/.codex/skills/agent-recall-consumer/SKILL.md');

        // Verify AGENTS.md retained project-owned content and removed managed block
        $agentsAfterUninstall = (string) file_get_contents($this->root . '/AGENTS.md');
        self::assertStringContainsString('# Custom Project Guidelines', $agentsAfterUninstall);
        self::assertStringContainsString('- Follow PSR-12', $agentsAfterUninstall);
        self::assertStringNotContainsString(InitSyncInstructionsCommand::BEGIN_MARKER, $agentsAfterUninstall);
        self::assertStringNotContainsString(InitSyncInstructionsCommand::END_MARKER, $agentsAfterUninstall);
        self::assertStringNotContainsString('agent-loop workflow router', $agentsAfterUninstall);
        self::assertStringNotContainsString('Agent Learning', $agentsAfterUninstall);
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
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
