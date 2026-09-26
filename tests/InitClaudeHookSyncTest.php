<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitStatusCommand;
use voku\AgentLoop\Init\InitSyncHooksCommand;
use voku\AgentLoop\Init\InitSyncManifest;
use voku\AgentLoop\Init\InitValidateCommand;
use voku\AgentLoop\Init\ManagedAssetSource;

/**
 * @internal
 */
final class InitClaudeHookSyncTest extends TestCase
{
    private string $root;

    /** @var array<string, false|string> */
    private array $envBackup = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-claude-hooks-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o775, true);

        foreach (['CLAUDE_CONFIG_DIR'] as $name) {
            $this->envBackup[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv($name . '=' . $value);
        }

        $this->removeDirectory($this->root);
    }

    public function testSyncMergesOwnedHooksAndPreservesUnrelatedProjectConfiguration(): void
    {
        $this->writeBundle();
        $this->writeSettings([
            'model' => 'opus',
            'theme' => 'auto',
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
                'PreToolUse' => [[
                    'matcher' => '^Bash$',
                    'hooks' => [['type' => 'command', 'command' => 'echo project-bash']],
                ]],
            ],
        ]);

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('merged agent-loop hook registrations', $result['output']);

        $settings = $this->readSettings();
        self::assertSame('opus', $settings['model']);
        self::assertSame('auto', $settings['theme']);
        self::assertSame(
            'echo project-stop',
            $settings['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        self::assertSame(
            ['echo project-bash', 'php .claude/hooks/policy.php'],
            $this->commands($settings['hooks']['PreToolUse'][0]['hooks'] ?? []),
        );

        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
        self::assertFileExists($this->root . '/.claude/hooks/.agent-loop-registration.json');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks.json');

        $manifest = $this->manifestEntries();
        self::assertArrayHasKey('hooks/.agent-loop-registration.json', $manifest);
        self::assertArrayHasKey('hooks/policy.php', $manifest);
        self::assertArrayNotHasKey('settings.json#hooks', $manifest);
        self::assertFalse($manifest['hooks/.agent-loop-registration.json']['adopted']);
    }

    public function testSyncPreservesUnrelatedEmptyJsonObjects(): void
    {
        $this->writeBundle();
        $this->writeSettings([
            'env' => new \stdClass(),
            'permissions' => new \stdClass(),
        ]);

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        $raw = file_get_contents($this->root . '/.claude/settings.json');
        self::assertIsString($raw);
        self::assertStringContainsString('"env": {}', $raw);
        self::assertStringContainsString('"permissions": {}', $raw);
    }

    public function testInterruptedFreshRegistrationTransactionRecoversBeforeRetry(): void
    {
        $this->writeBundle();
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
            ],
        ]);

        $settingsPath = $this->root . '/.claude/settings.json';
        $hooksDir = $this->root . '/.claude/hooks';
        mkdir($hooksDir, 0o775, true);
        copy($settingsPath, $this->root . '/.claude/.agent-loop-settings.bak');
        file_put_contents($hooksDir . '/.agent-loop-registration.absent', '');
        file_put_contents($this->root . '/.claude/.agent-loop-hook-registration.txn', "v1\n");

        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
                'PreToolUse' => [[
                    'matcher' => '^Bash    {
        $this->writeBundle();
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);

        $second = $this->runSync(['--agent=claude']);

        self::assertSame(0, $second['exit'], $second['output']);
        self::assertStringContainsString('kept agent-loop hook registrations', $second['output']);
        self::assertSame(
            1,
            count(array_filter(
                $this->allHookCommands($this->readSettings()),
                static fn (string $command): bool => $command === 'php .claude/hooks/policy.php',
            )),
        );
    }

    public function testConflictingOwnedCommandIdentityRequiresForceWithoutTouchingForeignHooks(): void
    {
        $this->writeBundle();
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
                'PreToolUse' => [[
                    'matcher' => '^Bash$',
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php .claude/hooks/policy.php',
                        'timeout' => 99,
                    ]],
                ]],
            ],
        ]);

        $blocked = $this->runSync(['--agent=claude']);
        self::assertSame(1, $blocked['exit']);
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/policy.php');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/.agent-loop-registration.json');
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        self::assertSame(99, $this->readSettings()['hooks']['PreToolUse'][0]['hooks'][0]['timeout'] ?? null);

        $forced = $this->runSync(['--agent=claude', '--force']);
        self::assertSame(0, $forced['exit'], $forced['output']);

        $settings = $this->readSettings();
        self::assertSame(
            'echo project-stop',
            $settings['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        $owned = array_values(array_filter(
            $settings['hooks']['PreToolUse'][0]['hooks'] ?? [],
            static fn (mixed $hook): bool => is_array($hook)
                && ($hook['command'] ?? null) === 'php .claude/hooks/policy.php',
        ));
        self::assertCount(1, $owned);
        self::assertArrayNotHasKey('timeout', $owned[0]);
    }

    public function testAdoptExistingAppliesToBundleFilesWithoutClaimingForeignRegistrations(): void
    {
        $this->writeBundle();
        mkdir($this->root . '/.claude/hooks', 0o775, true);
        file_put_contents($this->root . '/.claude/hooks/policy.php', "<?php\n\nreturn 'project-owned';\n");
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
            ],
        ]);

        $result = $this->runSync(['--agent=claude', '--adopt-existing']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('adopted existing', $result['output']);
        self::assertSame(
            "<?php\n\nreturn 'project-owned';\n",
            file_get_contents($this->root . '/.claude/hooks/policy.php'),
        );
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );

        $entries = $this->manifestEntries();
        self::assertTrue($entries['hooks/policy.php']['adopted']);
        self::assertFalse($entries['hooks/.agent-loop-registration.json']['adopted']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->writeBundle();

        $result = $this->runSync(['--agent=claude', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('merge agent-loop hook registrations', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.claude/settings.json');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/policy.php');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/.agent-loop-registration.json');
    }

    public function testRemovedOwnedHookIsPrunedWhileUnrelatedHooksSurvive(): void
    {
        $this->writeBundle(['policy.php', 'context.php']);
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
            ],
        ]);
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);
        self::assertFileExists($this->root . '/.claude/hooks/context.php');

        $this->writeBundle(['policy.php']);
        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('removed stale', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/context.php');
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        self::assertNotContains(
            'php .claude/hooks/context.php',
            $this->allHookCommands($this->readSettings()),
        );
    }

    public function testLegacyWholeKeyOwnershipMigratesWithoutDeletingForeignHooks(): void
    {
        $this->writeBundle();
        $sourceRoot = $this->root . '/resources/hooks/claude';
        mkdir($this->root . '/.claude/hooks', 0o775, true);
        copy($sourceRoot . '/hooks/policy.php', $this->root . '/.claude/hooks/policy.php');
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
                'PreToolUse' => [[
                    'matcher' => '^Bash$',
                    'hooks' => [['type' => 'command', 'command' => 'php .claude/hooks/policy.php']],
                ]],
            ],
        ]);

        $manifest = InitSyncManifest::load($this->root . '/.claude', 'hooks', 'claude');
        $manifest->writeProjections([
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

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('migrate legacy whole-key Claude hook ownership', $result['output']);
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );

        $entries = $this->manifestEntries();
        self::assertArrayNotHasKey('settings.json#hooks', $entries);
        self::assertArrayHasKey('hooks/.agent-loop-registration.json', $entries);
    }

    public function testStatusReportsMissingOwnedLiveRegistrationWithoutTreatingForeignHooksAsDrift(): void
    {
        $this->writeBundle();
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
            ],
        ]);
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);

        $settings = $this->readSettings();
        unset($settings['hooks']['PreToolUse']);
        $this->writeSettings($settings);

        ob_start();
        $exit = (new InitStatusCommand($this->root))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString(
            '[WARN] claude hooks: stale: hooks/.agent-loop-registration.json',
            $output,
        );
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
    }

    public function testValidateAcceptsClaudeBundleAndRejectsForeignHookDirectory(): void
    {
        $this->writeBundle();

        $valid = $this->runValidate(['--kind=hooks', '--agent=claude']);
        self::assertSame(0, $valid['exit'], $valid['output']);
        self::assertStringContainsString('[OK] validate hooks', $valid['output']);

        file_put_contents($this->root . '/resources/hooks/claude/hooks.json', json_encode([
            'hooks' => [
                'PreToolUse' => [
                    ['hooks' => [['type' => 'command', 'command' => 'php .codex/hooks/policy.php']]],
                ],
            ],
        ], \JSON_PRETTY_PRINT));

        $invalid = $this->runValidate(['--kind=hooks', '--agent=claude']);
        self::assertSame(1, $invalid['exit']);
        self::assertStringContainsString('must call one repository-local .claude/hooks PHP script', $invalid['output']);
    }

    public function testClaudeConfigDirDoesNotMoveRepositoryHooksIntoUserScope(): void
    {
        $this->writeBundle();
        putenv('CLAUDE_CONFIG_DIR=custom-claude');

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.claude/settings.json');
        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
        self::assertFileDoesNotExist($this->root . '/custom-claude/settings.json');
        self::assertFileDoesNotExist($this->root . '/custom-claude/hooks/policy.php');
    }

    /**
     * @param list<string> $scriptNames
     */
    private function writeBundle(array $scriptNames = ['policy.php']): void
    {
        $bundleRoot = $this->root . '/resources/hooks/claude';
        if (is_dir($bundleRoot . '/hooks')) {
            foreach ((array) glob($bundleRoot . '/hooks/*.php') as $existing) {
                unlink((string) $existing);
            }
        } else {
            mkdir($bundleRoot . '/hooks', 0o775, true);
        }

        $hooks = [];
        foreach ($scriptNames as $index => $scriptName) {
            file_put_contents($bundleRoot . '/hooks/' . $scriptName, "<?php\n\necho '{}';\n");
            $event = $index === 0 ? 'PreToolUse' : 'SessionStart';
            $hooks[$event][] = [
                'matcher' => '^Bash$',
                'hooks' => [['type' => 'command', 'command' => 'php .claude/hooks/' . $scriptName]],
            ];
        }

        file_put_contents(
            $bundleRoot . '/hooks.json',
            json_encode(['hooks' => $hooks], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeSettings(array $settings): void
    {
        if (!is_dir($this->root . '/.claude')) {
            mkdir($this->root . '/.claude', 0o775, true);
        }

        file_put_contents(
            $this->root . '/.claude/settings.json',
            json_encode($settings, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(): array
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifestEntries(): array
    {
        $manifest = json_decode(
            (string) file_get_contents($this->root . '/.claude/.agent-loop-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertSame(3, $manifest['version'] ?? null);
        self::assertSame('claude', $manifest['agent'] ?? null);

        $entries = $manifest['entries'] ?? null;
        self::assertIsArray($entries);

        /** @var array<string, array<string, mixed>> $indexed */
        $indexed = array_column($entries, null, 'target');

        return $indexed;
    }

    /**
     * @param list<mixed> $handlers
     * @return list<string>
     */
    private function commands(array $handlers): array
    {
        $commands = [];
        foreach ($handlers as $handler) {
            if (is_array($handler) && is_string($handler['command'] ?? null)) {
                $commands[] = $handler['command'];
            }
        }

        return $commands;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function allHookCommands(array $settings): array
    {
        $commands = [];
        $hooks = $settings['hooks'] ?? [];
        if (!is_array($hooks)) {
            return [];
        }
        foreach ($hooks as $groups) {
            if (!is_array($groups)) {
                continue;
            }
            foreach ($groups as $group) {
                if (!is_array($group) || !is_array($group['hooks'] ?? null)) {
                    continue;
                }
                array_push($commands, ...$this->commands($group['hooks']));
            }
        }

        return $commands;
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runSync(array $tokens): array
    {
        ob_start();
        $exit = (new InitSyncHooksCommand($this->root))->run($tokens);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runValidate(array $tokens): array
    {
        ob_start();
        $exit = (new InitValidateCommand($this->root))->run($tokens);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);

                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
,
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php .claude/hooks/policy.php',
                    ]],
                ]],
            ],
        ]);

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        self::assertSame(
            'php .claude/hooks/policy.php',
            $this->readSettings()['hooks']['PreToolUse'][0]['hooks'][0]['command'] ?? null,
        );
        self::assertFileExists($hooksDir . '/.agent-loop-registration.json');
        self::assertFileDoesNotExist($this->root . '/.claude/.agent-loop-hook-registration.txn');
        self::assertFileDoesNotExist($this->root . '/.claude/.agent-loop-settings.bak');
        self::assertFileDoesNotExist($hooksDir . '/.agent-loop-registration.absent');
    }

    public function testRepeatedSyncIsIdempotentAndDoesNotDuplicateOwnedHandler(): void
    {
        $this->writeBundle();
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);

        $second = $this->runSync(['--agent=claude']);

        self::assertSame(0, $second['exit'], $second['output']);
        self::assertStringContainsString('kept agent-loop hook registrations', $second['output']);
        self::assertSame(
            1,
            count(array_filter(
                $this->allHookCommands($this->readSettings()),
                static fn (string $command): bool => $command === 'php .claude/hooks/policy.php',
            )),
        );
    }

    public function testConflictingOwnedCommandIdentityRequiresForceWithoutTouchingForeignHooks(): void
    {
        $this->writeBundle();
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
                'PreToolUse' => [[
                    'matcher' => '^Bash$',
                    'hooks' => [[
                        'type' => 'command',
                        'command' => 'php .claude/hooks/policy.php',
                        'timeout' => 99,
                    ]],
                ]],
            ],
        ]);

        $blocked = $this->runSync(['--agent=claude']);
        self::assertSame(1, $blocked['exit']);
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/policy.php');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/.agent-loop-registration.json');
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        self::assertSame(99, $this->readSettings()['hooks']['PreToolUse'][0]['hooks'][0]['timeout'] ?? null);

        $forced = $this->runSync(['--agent=claude', '--force']);
        self::assertSame(0, $forced['exit'], $forced['output']);

        $settings = $this->readSettings();
        self::assertSame(
            'echo project-stop',
            $settings['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        $owned = array_values(array_filter(
            $settings['hooks']['PreToolUse'][0]['hooks'] ?? [],
            static fn (mixed $hook): bool => is_array($hook)
                && ($hook['command'] ?? null) === 'php .claude/hooks/policy.php',
        ));
        self::assertCount(1, $owned);
        self::assertArrayNotHasKey('timeout', $owned[0]);
    }

    public function testAdoptExistingAppliesToBundleFilesWithoutClaimingForeignRegistrations(): void
    {
        $this->writeBundle();
        mkdir($this->root . '/.claude/hooks', 0o775, true);
        file_put_contents($this->root . '/.claude/hooks/policy.php', "<?php\n\nreturn 'project-owned';\n");
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
            ],
        ]);

        $result = $this->runSync(['--agent=claude', '--adopt-existing']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('adopted existing', $result['output']);
        self::assertSame(
            "<?php\n\nreturn 'project-owned';\n",
            file_get_contents($this->root . '/.claude/hooks/policy.php'),
        );
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );

        $entries = $this->manifestEntries();
        self::assertTrue($entries['hooks/policy.php']['adopted']);
        self::assertFalse($entries['hooks/.agent-loop-registration.json']['adopted']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->writeBundle();

        $result = $this->runSync(['--agent=claude', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('merge agent-loop hook registrations', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.claude/settings.json');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/policy.php');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/.agent-loop-registration.json');
    }

    public function testRemovedOwnedHookIsPrunedWhileUnrelatedHooksSurvive(): void
    {
        $this->writeBundle(['policy.php', 'context.php']);
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
            ],
        ]);
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);
        self::assertFileExists($this->root . '/.claude/hooks/context.php');

        $this->writeBundle(['policy.php']);
        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('removed stale', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/context.php');
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
        self::assertNotContains(
            'php .claude/hooks/context.php',
            $this->allHookCommands($this->readSettings()),
        );
    }

    public function testLegacyWholeKeyOwnershipMigratesWithoutDeletingForeignHooks(): void
    {
        $this->writeBundle();
        $sourceRoot = $this->root . '/resources/hooks/claude';
        mkdir($this->root . '/.claude/hooks', 0o775, true);
        copy($sourceRoot . '/hooks/policy.php', $this->root . '/.claude/hooks/policy.php');
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
                'PreToolUse' => [[
                    'matcher' => '^Bash$',
                    'hooks' => [['type' => 'command', 'command' => 'php .claude/hooks/policy.php']],
                ]],
            ],
        ]);

        $manifest = InitSyncManifest::load($this->root . '/.claude', 'hooks', 'claude');
        $manifest->writeProjections([
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

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('migrate legacy whole-key Claude hook ownership', $result['output']);
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );

        $entries = $this->manifestEntries();
        self::assertArrayNotHasKey('settings.json#hooks', $entries);
        self::assertArrayHasKey('hooks/.agent-loop-registration.json', $entries);
    }

    public function testStatusReportsMissingOwnedLiveRegistrationWithoutTreatingForeignHooksAsDrift(): void
    {
        $this->writeBundle();
        $this->writeSettings([
            'hooks' => [
                'Stop' => [[
                    'hooks' => [['type' => 'command', 'command' => 'echo project-stop']],
                ]],
            ],
        ]);
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);

        $settings = $this->readSettings();
        unset($settings['hooks']['PreToolUse']);
        $this->writeSettings($settings);

        ob_start();
        $exit = (new InitStatusCommand($this->root))->run([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit, $output);
        self::assertStringContainsString(
            '[WARN] claude hooks: stale: hooks/.agent-loop-registration.json',
            $output,
        );
        self::assertSame(
            'echo project-stop',
            $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command'] ?? null,
        );
    }

    public function testValidateAcceptsClaudeBundleAndRejectsForeignHookDirectory(): void
    {
        $this->writeBundle();

        $valid = $this->runValidate(['--kind=hooks', '--agent=claude']);
        self::assertSame(0, $valid['exit'], $valid['output']);
        self::assertStringContainsString('[OK] validate hooks', $valid['output']);

        file_put_contents($this->root . '/resources/hooks/claude/hooks.json', json_encode([
            'hooks' => [
                'PreToolUse' => [
                    ['hooks' => [['type' => 'command', 'command' => 'php .codex/hooks/policy.php']]],
                ],
            ],
        ], \JSON_PRETTY_PRINT));

        $invalid = $this->runValidate(['--kind=hooks', '--agent=claude']);
        self::assertSame(1, $invalid['exit']);
        self::assertStringContainsString('must call one repository-local .claude/hooks PHP script', $invalid['output']);
    }

    public function testClaudeConfigDirDoesNotMoveRepositoryHooksIntoUserScope(): void
    {
        $this->writeBundle();
        putenv('CLAUDE_CONFIG_DIR=custom-claude');

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/.claude/settings.json');
        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
        self::assertFileDoesNotExist($this->root . '/custom-claude/settings.json');
        self::assertFileDoesNotExist($this->root . '/custom-claude/hooks/policy.php');
    }

    /**
     * @param list<string> $scriptNames
     */
    private function writeBundle(array $scriptNames = ['policy.php']): void
    {
        $bundleRoot = $this->root . '/resources/hooks/claude';
        if (is_dir($bundleRoot . '/hooks')) {
            foreach ((array) glob($bundleRoot . '/hooks/*.php') as $existing) {
                unlink((string) $existing);
            }
        } else {
            mkdir($bundleRoot . '/hooks', 0o775, true);
        }

        $hooks = [];
        foreach ($scriptNames as $index => $scriptName) {
            file_put_contents($bundleRoot . '/hooks/' . $scriptName, "<?php\n\necho '{}';\n");
            $event = $index === 0 ? 'PreToolUse' : 'SessionStart';
            $hooks[$event][] = [
                'matcher' => '^Bash$',
                'hooks' => [['type' => 'command', 'command' => 'php .claude/hooks/' . $scriptName]],
            ];
        }

        file_put_contents(
            $bundleRoot . '/hooks.json',
            json_encode(['hooks' => $hooks], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeSettings(array $settings): void
    {
        if (!is_dir($this->root . '/.claude')) {
            mkdir($this->root . '/.claude', 0o775, true);
        }

        file_put_contents(
            $this->root . '/.claude/settings.json',
            json_encode($settings, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(): array
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

    /**
     * @return array<string, array<string, mixed>>
     */
    private function manifestEntries(): array
    {
        $manifest = json_decode(
            (string) file_get_contents($this->root . '/.claude/.agent-loop-manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        self::assertIsArray($manifest);
        self::assertSame(3, $manifest['version'] ?? null);
        self::assertSame('claude', $manifest['agent'] ?? null);

        $entries = $manifest['entries'] ?? null;
        self::assertIsArray($entries);

        /** @var array<string, array<string, mixed>> $indexed */
        $indexed = array_column($entries, null, 'target');

        return $indexed;
    }

    /**
     * @param list<mixed> $handlers
     * @return list<string>
     */
    private function commands(array $handlers): array
    {
        $commands = [];
        foreach ($handlers as $handler) {
            if (is_array($handler) && is_string($handler['command'] ?? null)) {
                $commands[] = $handler['command'];
            }
        }

        return $commands;
    }

    /**
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function allHookCommands(array $settings): array
    {
        $commands = [];
        $hooks = $settings['hooks'] ?? [];
        if (!is_array($hooks)) {
            return [];
        }
        foreach ($hooks as $groups) {
            if (!is_array($groups)) {
                continue;
            }
            foreach ($groups as $group) {
                if (!is_array($group) || !is_array($group['hooks'] ?? null)) {
                    continue;
                }
                array_push($commands, ...$this->commands($group['hooks']));
            }
        }

        return $commands;
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runSync(array $tokens): array
    {
        ob_start();
        $exit = (new InitSyncHooksCommand($this->root))->run($tokens);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    /**
     * @param list<string> $tokens
     * @return array{exit: int, output: string}
     */
    private function runValidate(array $tokens): array
    {
        ob_start();
        $exit = (new InitValidateCommand($this->root))->run($tokens);

        return ['exit' => $exit, 'output' => (string) ob_get_clean()];
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..' || !is_string($entry)) {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);

                continue;
            }

            unlink($child);
        }

        rmdir($path);
    }
}
