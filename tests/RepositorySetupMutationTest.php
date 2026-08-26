<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\InitSyncInstructionsCommand;
use voku\AgentLoop\Init\ManagedAssetChangePlan;
use voku\AgentLoop\Init\ManagedAssetKind;
use voku\AgentLoop\Init\ManagedAssetOperation;
use voku\AgentLoop\Init\RepositorySetupService;
use voku\AgentLoop\Init\StaleRepositorySetupPlan;

final class RepositorySetupMutationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-mutation-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root . '/fixture/skills/managed-skill', 0o775, true)
            && !is_dir($this->root . '/fixture/skills/managed-skill')) {
            throw new RuntimeException('Unable to create setup mutation fixture.');
        }
        file_put_contents($this->root . '/fixture/skills/managed-skill/SKILL.md', "# Managed skill\n");
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testSuccessfulTypedInstallAppliesAssetsAndInstructionMarkers(): void
    {
        $service = new RepositorySetupService($this->root);
        $plan = $service->planInstall('codex', false, $this->paths());

        self::assertNotNull($this->operation($plan, 'managed-skill'));
        $instructions = $this->operation($plan, 'AGENTS.md');
        self::assertNotNull($instructions);
        self::assertSame(ManagedAssetKind::INSTRUCTIONS, $instructions->kind);

        $result = $service->install($plan, $plan->expectedState->value, $this->paths());

        self::assertTrue($result->succeeded);
        self::assertContains('managed-skill', $this->entries($result->applied));
        self::assertContains('AGENTS.md', $this->entries($result->applied));
        self::assertFileExists($this->root . '/.codex/skills/managed-skill/SKILL.md');
        self::assertFileExists($this->root . '/AGENTS.md');
        $instructionsContent = file_get_contents($this->root . '/AGENTS.md');
        self::assertIsString($instructionsContent);
        self::assertStringContainsString(InitSyncInstructionsCommand::BEGIN_MARKER, $instructionsContent);
        self::assertStringContainsString(InitSyncInstructionsCommand::END_MARKER, $instructionsContent);
    }

    public function testInstallRefusesSourceDriftBeforeTheFirstWrite(): void
    {
        $service = new RepositorySetupService($this->root);
        $plan = $service->planInstall('codex', false, $this->paths());
        file_put_contents($this->root . '/fixture/skills/managed-skill/SKILL.md', "# Changed after preview\n");

        try {
            $service->install($plan, $plan->expectedState->value, $this->paths());
            self::fail('Expected stale source evidence to invalidate the install plan.');
        } catch (StaleRepositorySetupPlan) {
            self::assertFileDoesNotExist($this->root . '/.codex/skills/managed-skill/SKILL.md');
            self::assertFileDoesNotExist($this->root . '/AGENTS.md');
        }
    }

    public function testInstallRejectsCraftedTargetOutsideTheManagedOwnerRoot(): void
    {
        $service = new RepositorySetupService($this->root);
        $plan = $service->planInstall('codex', false, $this->paths());
        $original = $this->operation($plan, 'managed-skill');
        self::assertNotNull($original);

        $victim = $this->root . '/project-owned.txt';
        file_put_contents($victim, "keep me\n");
        $operations = [];
        foreach ($plan->operations as $operation) {
            $operations[] = $operation->entry === 'managed-skill'
                ? new ManagedAssetOperation(
                    $operation->operation,
                    $operation->host,
                    $operation->kind,
                    $operation->entry,
                    $victim,
                    $operation->reason,
                )
                : $operation;
        }
        $crafted = new ManagedAssetChangePlan(
            $plan->intent,
            $plan->agent,
            $plan->withHooks,
            $plan->expectedState,
            $operations,
            $plan->blocked,
        );

        try {
            $service->install($crafted, $crafted->expectedState->value, $this->paths());
            self::fail('Expected the crafted install target to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame("keep me\n", file_get_contents($victim));
            self::assertFileDoesNotExist($this->root . '/.codex/skills/managed-skill/SKILL.md');
            self::assertFileDoesNotExist($this->root . '/AGENTS.md');
        }
    }

    public function testMalformedInstructionMarkersBlockTheWholeInstallBeforeAssetWrites(): void
    {
        file_put_contents($this->root . '/AGENTS.md', InitSyncInstructionsCommand::BEGIN_MARKER . "\nproject text\n");
        $before = file_get_contents($this->root . '/AGENTS.md');
        self::assertIsString($before);

        $service = new RepositorySetupService($this->root);
        $plan = $service->planInstall('codex', false, $this->paths());

        $blocked = $this->operation($plan, 'AGENTS.md', true);
        self::assertNotNull($blocked);
        self::assertSame(ManagedAssetKind::INSTRUCTIONS, $blocked->kind);

        $result = $service->install($plan, $plan->expectedState->value, $this->paths());

        self::assertFalse($result->succeeded);
        self::assertSame($before, file_get_contents($this->root . '/AGENTS.md'));
        self::assertFileDoesNotExist($this->root . '/.codex/skills/managed-skill/SKILL.md');
    }

    public function testTypedUninstallRemovesOnlyManagedInstructionBlockAndManagedAssets(): void
    {
        $service = new RepositorySetupService($this->root);
        $install = $service->planInstall('codex', false, $this->paths());
        $installResult = $service->install($install, $install->expectedState->value, $this->paths());
        self::assertTrue($installResult->succeeded);

        $managedInstructions = file_get_contents($this->root . '/AGENTS.md');
        self::assertIsString($managedInstructions);
        file_put_contents($this->root . '/AGENTS.md', "project before\n" . $managedInstructions . "project after\n");

        $uninstall = $service->planUninstall('codex', false, $this->paths());
        $result = $service->uninstall($uninstall, $uninstall->expectedState->value, $this->paths());

        self::assertTrue($result->succeeded);
        self::assertContains('AGENTS.md', $this->entries($result->applied));
        self::assertContains('managed-skill', $this->entries($result->applied));
        self::assertFileDoesNotExist($this->root . '/.codex/skills/managed-skill/SKILL.md');
        $remaining = file_get_contents($this->root . '/AGENTS.md');
        self::assertIsString($remaining);
        self::assertStringContainsString('project before', $remaining);
        self::assertStringContainsString('project after', $remaining);
        self::assertStringNotContainsString(InitSyncInstructionsCommand::BEGIN_MARKER, $remaining);
        self::assertStringNotContainsString(InitSyncInstructionsCommand::END_MARKER, $remaining);
    }

    private function paths(): AgentAssetSourcePaths
    {
        return new AgentAssetSourcePaths(
            $this->root,
            'fixture/skills',
            'fixture/subagents',
            'fixture/hooks',
            'fixture/tools',
            'fixture/claude-hooks',
        );
    }

    private function operation(ManagedAssetChangePlan $plan, string $entry, bool $blocked = false): ?ManagedAssetOperation
    {
        foreach ($blocked ? $plan->blocked : $plan->operations as $operation) {
            if ($operation->entry === $entry) {
                return $operation;
            }
        }

        return null;
    }

    /** @param list<ManagedAssetOperation> $operations @return list<string> */
    private function entries(array $operations): array
    {
        return array_map(
            static fn (ManagedAssetOperation $operation): string => $operation->entry,
            $operations,
        );
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
