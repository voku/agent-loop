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
use voku\AgentLoop\Init\InitSyncManifest;
use voku\AgentLoop\Init\ManagedAssetChangePlan;
use voku\AgentLoop\Init\ManagedAssetKind;
use voku\AgentLoop\Init\ManagedAssetOperation;
use voku\AgentLoop\Init\ManagedAssetOperationKind;
use voku\AgentLoop\Init\ManagedAssetSource;
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


    public function testTypedClaudeLegacyHookOwnershipMustMigrateBeforeUninstall(): void
    {
        $this->writeClaudeHookBundle();
        $sourceRoot = $this->root . '/fixture/claude-hooks';
        if (!mkdir($this->root . '/.claude/hooks', 0o775, true) && !is_dir($this->root . '/.claude/hooks')) {
            self::fail('Unable to create legacy Claude hook target.');
        }
        copy($sourceRoot . '/hooks/policy.php', $this->root . '/.claude/hooks/policy.php');
        file_put_contents(
            $this->root . '/.claude/settings.json',
            json_encode([
                'hooks' => [
                    'Stop' => [[
                        'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                    ]],
                    'PreToolUse' => [[
                        'matcher' => '^Bash$',
                        'hooks' => [[
                            'type' => 'command',
                            'command' => 'php .claude/hooks/policy.php',
                        ]],
                    ]],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        InitSyncManifest::load($this->root . '/.claude', 'hooks', 'claude')->writeProjections([
            'settings.json#hooks' => ManagedAssetSource::fromPath(
                $this->root,
                $sourceRoot . '/hooks.json',
                'hooks:claude:settings-hooks',
            ),
            'hooks/policy.php' => ManagedAssetSource::fromPath(
                $this->root,
                $sourceRoot . '/hooks/policy.php',
                'hooks:claude:hooks/policy.php',
            ),
        ], []);

        $plan = (new RepositorySetupService($this->root))->planUninstall('claude', true, $this->paths());

        $blocked = $this->operation($plan, 'hooks/.agent-loop-registration.json', true);
        self::assertNotNull($blocked);
        self::assertStringContainsString('must be migrated', (string) $blocked->reason);
        foreach ($plan->operations as $operation) {
            self::assertNotSame(ManagedAssetKind::HOOKS, $operation->kind);
        }

        $settings = $this->readClaudeSettings();
        self::assertSame('echo project-stop', $settings['hooks']['Stop'][0]['hooks'][0]['command'] ?? null);
        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
    }

    public function testTypedClaudeHookInstallPreflightsCommandOwnershipBeforeAnyWrite(): void
    {
        $this->writeClaudeHookBundle();
        if (!is_dir($this->root . '/.claude')) {
            mkdir($this->root . '/.claude', 0o775, true);
        }
        file_put_contents(
            $this->root . '/.claude/settings.json',
            json_encode([
                'hooks' => [
                    'PreToolUse' => [[
                        'matcher' => '^Bash$',
                        'hooks' => [[
                            'type' => 'command',
                            'command' => 'php .claude/hooks/policy.php',
                            'timeout' => 99,
                        ]],
                    ]],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $service = new RepositorySetupService($this->root);
        $plan = $service->planInstall('claude', true, $this->paths());

        try {
            $service->install($plan, $plan->expectedState->value, $this->paths());
            self::fail('Expected unowned Claude command identity to fail before mutation.');
        } catch (InvalidArgumentException) {
            self::assertFileDoesNotExist($this->root . '/.claude/hooks/policy.php');
            self::assertFileDoesNotExist($this->root . '/.claude/hooks/.agent-loop-registration.json');
            self::assertFileDoesNotExist($this->root . '/.claude/skills/managed-skill/SKILL.md');
            self::assertFileDoesNotExist($this->root . '/AGENTS.md');
        }
    }

    public function testTypedClaudeUninstallKeepsRegistrationsWhenAssetPreflightBlocks(): void
    {
        $this->writeClaudeHookBundle();
        $service = new RepositorySetupService($this->root);
        $install = $service->planInstall('claude', true, $this->paths());
        self::assertTrue($service->install($install, $install->expectedState->value, $this->paths())->succeeded);

        $uninstall = $service->planUninstall('claude', true, $this->paths());
        $crafted = new ManagedAssetChangePlan(
            $uninstall->intent,
            $uninstall->agent,
            $uninstall->withHooks,
            $uninstall->expectedState,
            [
                ...$uninstall->operations,
                new ManagedAssetOperation(
                    ManagedAssetOperationKind::REMOVE,
                    'claude',
                    ManagedAssetKind::HOOKS,
                    'hooks/unmanaged.php',
                    $this->root . '/.claude/hooks/unmanaged.php',
                ),
            ],
            $uninstall->blocked,
        );

        $result = $service->uninstall($crafted, $crafted->expectedState->value, $this->paths());

        self::assertFalse($result->succeeded);
        self::assertNotNull($this->operation($result->plan, 'hooks/.agent-loop-registration.json'));
        self::assertFileExists($this->root . '/.claude/hooks/.agent-loop-registration.json');
        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
        self::assertFileExists($this->root . '/.claude/skills/managed-skill/SKILL.md');
        self::assertFileExists($this->root . '/AGENTS.md');
        $settings = $this->readClaudeSettings();
        self::assertSame(
            'php .claude/hooks/policy.php',
            $settings['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        );
    }

    public function testTypedClaudeHookInstallAndUninstallPreserveForeignProjectHooks(): void
    {
        $this->writeClaudeHookBundle();
        if (!is_dir($this->root . '/.claude')) {
            mkdir($this->root . '/.claude', 0o775, true);
        }
        file_put_contents(
            $this->root . '/.claude/settings.json',
            json_encode([
                'model' => 'opus',
                'hooks' => [
                    'Stop' => [[
                        'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                    ]],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        $service = new RepositorySetupService($this->root);
        $install = $service->planInstall('claude', true, $this->paths());
        self::assertNotNull($this->operation($install, 'hooks/.agent-loop-registration.json'));
        self::assertNotNull($this->operation($install, 'hooks/policy.php'));

        $installed = $service->install($install, $install->expectedState->value, $this->paths());

        self::assertTrue($installed->succeeded);
        self::assertFileExists($this->root . '/.claude/hooks/.agent-loop-registration.json');
        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
        $settings = $this->readClaudeSettings();
        self::assertSame('opus', $settings['model'] ?? null);
        self::assertSame('echo project-stop', $settings['hooks']['Stop'][0]['hooks'][0]['command'] ?? null);
        self::assertSame(
            'php .claude/hooks/policy.php',
            $settings['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        );

        $uninstall = $service->planUninstall('claude', true, $this->paths());
        self::assertNotNull($this->operation($uninstall, 'hooks/.agent-loop-registration.json'));
        $removed = $service->uninstall($uninstall, $uninstall->expectedState->value, $this->paths());

        self::assertTrue($removed->succeeded);
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/.agent-loop-registration.json');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/policy.php');
        $remaining = $this->readClaudeSettings();
        self::assertSame('opus', $remaining['model'] ?? null);
        self::assertSame('echo project-stop', $remaining['hooks']['Stop'][0]['hooks'][0]['command'] ?? null);
        self::assertArrayNotHasKey('PreToolUse', $remaining['hooks']);
    }

    public function testTypedGitIntegrationUsesRepositoryDeclaredPolicyWithoutCliDispatch(): void
    {
        if (!mkdir($this->root . '/.agent-loop', 0o775, true) && !is_dir($this->root . '/.agent-loop')) {
            self::fail('Unable to create Git integration policy directory.');
        }
        file_put_contents($this->root . '/.agent-loop/githooks.json', "{}\n");

        exec('git -C ' . escapeshellarg($this->root) . ' init -q', $output, $exitCode);
        self::assertSame(0, $exitCode, 'Git is required for the typed Git integration regression.');

        $service = new RepositorySetupService($this->root);
        $service->syncGitIntegration();

        self::assertFileExists($this->root . '/.githooks/pre-commit');
        self::assertFileExists($this->root . '/.githooks/commit-msg');
        self::assertFileExists($this->root . '/.githooks/.agent-loop-manifest.json');
        exec(
            'git -C ' . escapeshellarg($this->root) . ' config --get core.hooksPath',
            $configOutput,
            $configExit,
        );
        self::assertSame(0, $configExit);
        self::assertSame(['.githooks'], $configOutput);
    }


    private function writeClaudeHookBundle(): void
    {
        $root = $this->root . '/fixture/claude-hooks';
        if (!mkdir($root . '/hooks', 0o775, true) && !is_dir($root . '/hooks')) {
            throw new RuntimeException('Unable to create Claude hook fixture.');
        }
        file_put_contents($root . '/hooks/policy.php', "<?php\n\necho '{}';\n");
        file_put_contents(
            $root . '/hooks.json',
            json_encode([
                'hooks' => [
                    'PreToolUse' => [[
                        'matcher' => '^Bash$',
                        'hooks' => [[
                            'type' => 'command',
                            'command' => 'php .claude/hooks/policy.php',
                        ]],
                    ]],
                ],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string, mixed> */
    private function readClaudeSettings(): array
    {
        $decoded = json_decode(
            (string) file_get_contents($this->root . '/.claude/settings.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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

    /**
     * @param list<ManagedAssetOperation> $operations
     * @return list<string>
     */
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
