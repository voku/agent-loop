<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Init\AgentAssetSourcePaths;
use voku\AgentLoop\Init\HostRuntimeProbe;
use voku\AgentLoop\Init\InitSyncManifest;
use voku\AgentLoop\Init\ManagedAssetChangePlan;
use voku\AgentLoop\Init\ManagedAssetOperation;
use voku\AgentLoop\Init\ManagedAssetOperationKind;
use voku\AgentLoop\Init\ManagedAssetSource;
use voku\AgentLoop\Init\RepositorySetupService;
use voku\AgentLoop\Init\StaleRepositorySetupPlan;

/**
 * @internal
 */
final class RepositorySetupUninstallTest extends TestCase
{
    private string $root;

    private string $skillsTarget;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-setup-uninstall-' . bin2hex(random_bytes(6));
        $this->skillsTarget = $this->root . '/.codex/skills';
        if (!mkdir($this->skillsTarget, 0o775, true) && !is_dir($this->skillsTarget)) {
            throw new RuntimeException('Unable to create uninstall fixture root.');
        }
        mkdir($this->root . '/bin', 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testUnchangedManagedEntriesAreRemovedAndProjectOwnedSiblingsSurvive(): void
    {
        $this->projectManagedSkill('managed-skill');
        file_put_contents($this->skillsTarget . '/README.md', "project owned\n");
        mkdir($this->skillsTarget . '/hand-written', 0o775, true);
        file_put_contents($this->skillsTarget . '/hand-written/SKILL.md', "mine\n");

        $service = $this->service();
        $plan = $service->planUninstall('codex', false, $this->paths());
        $result = $service->uninstall($plan, $plan->expectedState->value, $this->paths());

        self::assertTrue($result->succeeded);
        self::assertSame(['managed-skill'], $this->entriesOf($result->applied));
        self::assertDirectoryDoesNotExist($this->skillsTarget . '/managed-skill');
        self::assertFileExists($this->skillsTarget . '/README.md');
        self::assertFileExists($this->skillsTarget . '/hand-written/SKILL.md');
    }

    public function testLocallyModifiedManagedEntryBlocksRemovalAndIsReportedPrecisely(): void
    {
        $this->projectManagedSkill('managed-skill');
        file_put_contents($this->skillsTarget . '/managed-skill/SKILL.md', "locally edited\n");

        $service = $this->service();
        $plan = $service->planUninstall('codex', false, $this->paths());

        self::assertSame([], $this->entriesOf($plan->operations));
        $blocked = $this->blockedFor($plan, 'managed-skill');
        self::assertNotNull($blocked);
        self::assertStringContainsString('modified locally', (string) $blocked->reason);
        self::assertFileExists($this->skillsTarget . '/managed-skill/SKILL.md');
    }

    public function testMissingManifestFailsClosedInsteadOfGlobDeletingTheHostDirectory(): void
    {
        mkdir($this->skillsTarget . '/looks-managed', 0o775, true);
        file_put_contents($this->skillsTarget . '/looks-managed/SKILL.md', "not ours\n");

        $plan = $this->service()->planUninstall('codex', false, $this->paths());

        self::assertSame([], $plan->operations);
        self::assertNotSame([], $plan->blocked);
        self::assertStringContainsString('No manifest proves', (string) $plan->blocked[0]->reason);
        self::assertFileExists($this->skillsTarget . '/looks-managed/SKILL.md');
    }

    public function testUnreadableManifestFailsClosed(): void
    {
        file_put_contents($this->skillsTarget . '/' . InitSyncManifest::fileName(), '{ broken');

        $plan = $this->service()->planUninstall('codex', false, $this->paths());

        self::assertSame([], $plan->operations);
        self::assertNotSame([], $plan->blocked);
    }

    public function testOptionalHooksAreOutOfScopeUnlessExplicitlySelected(): void
    {
        $this->projectManagedSkill('managed-skill');

        $withoutHooks = $this->service()->planUninstall('codex', false, $this->paths());
        foreach ([...$withoutHooks->operations, ...$withoutHooks->blocked] as $operation) {
            self::assertNotSame('hooks', $operation->kind->value);
        }

        $withHooks = $this->service()->planUninstall('codex', true, $this->paths());
        $kinds = [];
        foreach ([...$withHooks->operations, ...$withHooks->blocked] as $operation) {
            $kinds[] = $operation->kind->value;
        }
        self::assertContains('hooks', $kinds);
    }

    public function testRepeatedUninstallIsDeterministicAndNonDestructive(): void
    {
        $this->projectManagedSkill('managed-skill');
        file_put_contents($this->skillsTarget . '/README.md', "project owned\n");

        $service = $this->service();
        $first = $service->planUninstall('codex', false, $this->paths());
        $service->uninstall($first, $first->expectedState->value, $this->paths());
        $snapshot = $this->snapshot();

        $second = $service->planUninstall('codex', false, $this->paths());
        self::assertSame([], $second->operations);
        $service->uninstall($second, $second->expectedState->value, $this->paths());

        self::assertSame($snapshot, $this->snapshot());
    }

    public function testStaleExpectedStateIsRefusedAndNothingIsDeleted(): void
    {
        $this->projectManagedSkill('managed-skill');

        $service = $this->service();
        $plan = $service->planUninstall('codex', false, $this->paths());

        // The repository moves after the plan was rendered.
        file_put_contents($this->skillsTarget . '/managed-skill/SKILL.md', "changed after planning\n");

        $this->expectException(StaleRepositorySetupPlan::class);

        try {
            $service->uninstall($plan, $plan->expectedState->value, $this->paths());
        } finally {
            self::assertFileExists($this->skillsTarget . '/managed-skill/SKILL.md');
        }
    }

    public function testAWrongExpectedStateTokenIsRefused(): void
    {
        $this->projectManagedSkill('managed-skill');
        $service = $this->service();
        $plan = $service->planUninstall('codex', false, $this->paths());

        $this->expectException(StaleRepositorySetupPlan::class);
        $service->uninstall($plan, 'setup-state:sha256:' . str_repeat('0', 64), $this->paths());
    }

    public function testPlanningNeverMutatesTheRepository(): void
    {
        $this->projectManagedSkill('managed-skill');
        $before = $this->snapshot();

        $this->service()->planUninstall('codex', false, $this->paths());
        $this->service()->planInstall('codex', false, $this->paths());

        self::assertSame($before, $this->snapshot());
    }

    public function testPlanIdIsStableForTheSamePlanAndChangesWithContent(): void
    {
        $this->projectManagedSkill('managed-skill');
        $service = $this->service();

        $first = $service->planUninstall('codex', false, $this->paths());
        $second = $service->planUninstall('codex', false, $this->paths());
        self::assertSame($first->planId(), $second->planId());

        $withHooks = $service->planUninstall('codex', true, $this->paths());
        self::assertNotSame($first->planId(), $withHooks->planId());
    }

    public function testInstallPlanDistinguishesAddFromUpdateAndRefusesProjectOwnedPaths(): void
    {
        $this->projectManagedSkill('managed-skill');

        $plan = $this->service()->planInstall('codex', false, $this->paths());

        self::assertSame(ManagedAssetChangePlan::INTENT_INSTALL, $plan->intent);
        $updates = $this->entriesOf($plan->operationsOfKind(ManagedAssetOperationKind::UPDATE));
        self::assertContains('managed-skill', $updates);
    }

    private function service(): RepositorySetupService
    {
        return new RepositorySetupService(
            $this->root,
            new HostRuntimeProbe($this->root . '/bin', DIRECTORY_SEPARATOR === '\\' ? '.EXE' : null),
        );
    }

    private function paths(): AgentAssetSourcePaths
    {
        return AgentAssetSourcePaths::fromSources($this->root, [], []);
    }

    /** Projects one managed skill exactly the way the sync commands do. */
    private function projectManagedSkill(string $entry): void
    {
        $sourceRoot = $this->root . '/resources/skills/' . $entry;
        if (!mkdir($sourceRoot, 0o775, true) && !is_dir($sourceRoot)) {
            throw new RuntimeException('Unable to create fixture skill source.');
        }
        file_put_contents($sourceRoot . '/SKILL.md', "# " . $entry . "\n");

        $targetDir = $this->skillsTarget . '/' . $entry;
        if (!mkdir($targetDir, 0o775, true) && !is_dir($targetDir)) {
            throw new RuntimeException('Unable to create fixture skill target.');
        }
        copy($sourceRoot . '/SKILL.md', $targetDir . '/SKILL.md');

        $manifest = InitSyncManifest::load($this->skillsTarget, 'skills', 'codex');
        $manifest->writeProjections(
            [$entry => ManagedAssetSource::fromPath($this->root, $sourceRoot, 'skills:codex:' . $entry)],
            [],
        );
    }

    /**
     * @param list<ManagedAssetOperation> $operations
     * @return list<string>
     */
    private function entriesOf(array $operations): array
    {
        $entries = [];
        foreach ($operations as $operation) {
            $entries[] = $operation->entry;
        }
        sort($entries, SORT_STRING);

        return $entries;
    }

    private function blockedFor(ManagedAssetChangePlan $plan, string $entry): ?ManagedAssetOperation
    {
        foreach ($plan->blocked as $operation) {
            if ($operation->entry === $entry) {
                return $operation;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function snapshot(): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $item) {
            $entries[] = $item->getPathname() . ':' . ($item->isFile() ? (string) $item->getSize() : 'dir');
        }
        sort($entries, SORT_STRING);

        return $entries;
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
