<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Init\InitSyncHooksCommand;
use voku\AgentLoop\Init\InitValidateCommand;

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

    public function testSyncWritesHooksKeyAndScriptsWithoutTouchingOtherSettings(): void
    {
        $this->writeBundle();
        $this->writeSettings(['model' => 'opus', 'theme' => 'auto']);

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('wrote hooks key', $result['output']);

        $settings = $this->readSettings();
        self::assertSame('opus', $settings['model']);
        self::assertSame('auto', $settings['theme']);
        self::assertArrayHasKey('PreToolUse', $settings['hooks']);
        self::assertSame(
            'php .claude/hooks/policy.php',
            $settings['hooks']['PreToolUse'][0]['hooks'][0]['command'],
        );

        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks.json');

        $manifest = json_decode((string) file_get_contents($this->root . '/.claude/.agent-loop-manifest.json'), true);
        self::assertSame('claude', $manifest['agent']);
        self::assertContains('settings.json#hooks', $manifest['entries']);
        self::assertContains('hooks/policy.php', $manifest['entries']);
    }

    public function testSyncRefusesUnmanagedHooksKeyUntilForced(): void
    {
        $this->writeBundle();
        $this->writeSettings(['hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => 'echo hand-written']]]]]]);

        $blocked = $this->runSync(['--agent=claude']);
        self::assertSame(1, $blocked['exit']);
        self::assertStringContainsString('unmanaged target already exists', $blocked['output']);
        self::assertSame('echo hand-written', $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command']);

        $forced = $this->runSync(['--agent=claude', '--force']);
        self::assertSame(0, $forced['exit'], $forced['output']);
        self::assertArrayNotHasKey('Stop', $this->readSettings()['hooks']);
    }

    public function testAdoptExistingKeepsHooksKeyContent(): void
    {
        $this->writeBundle();
        $this->writeSettings(['hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => 'echo hand-written']]]]]]);

        $result = $this->runSync(['--agent=claude', '--adopt-existing']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('adopted existing hooks key', $result['output']);
        self::assertSame('echo hand-written', $this->readSettings()['hooks']['Stop'][0]['hooks'][0]['command']);
    }

    public function testDryRunWritesNothing(): void
    {
        $this->writeBundle();

        $result = $this->runSync(['--agent=claude', '--dry-run']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('[DRY-RUN] sync hooks: write hooks key', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.claude/settings.json');
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/policy.php');
    }

    public function testRemovedHookScriptIsCleanedUpOnNextSync(): void
    {
        $this->writeBundle(['policy.php', 'context.php']);
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);
        self::assertFileExists($this->root . '/.claude/hooks/context.php');

        $this->writeBundle(['policy.php']);
        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertStringContainsString('removed stale', $result['output']);
        self::assertFileDoesNotExist($this->root . '/.claude/hooks/context.php');
        self::assertFileExists($this->root . '/.claude/hooks/policy.php');
    }

    public function testRemovingTheWholeBundleAlsoDropsTheHooksKeyButKeepsOtherSettings(): void
    {
        $this->writeBundle();
        $this->writeSettings(['model' => 'opus']);
        self::assertSame(0, $this->runSync(['--agent=claude'])['exit']);

        // An empty bundle is the "no hooks at all" state; the manifest still owns the key.
        file_put_contents($this->root . '/docs/agents/claude-hooks/hooks.json', json_encode([
            'hooks' => [
                'PreToolUse' => [
                    ['matcher' => '^Bash$', 'hooks' => [['type' => 'command', 'command' => 'php .claude/hooks/policy.php']]],
                ],
            ],
        ], \JSON_PRETTY_PRINT));

        $result = $this->runSync(['--agent=claude']);
        self::assertSame(0, $result['exit'], $result['output']);
        self::assertSame('opus', $this->readSettings()['model']);
    }

    public function testValidateAcceptsClaudeBundleAndRejectsForeignHookDirectory(): void
    {
        $this->writeBundle();

        $valid = $this->runValidate(['--kind=hooks', '--agent=claude']);
        self::assertSame(0, $valid['exit'], $valid['output']);
        self::assertStringContainsString('[OK] validate hooks', $valid['output']);

        file_put_contents($this->root . '/docs/agents/claude-hooks/hooks.json', json_encode([
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

    public function testClaudeConfigDirOverridesTheTargetRoot(): void
    {
        $this->writeBundle();
        putenv('CLAUDE_CONFIG_DIR=custom-claude');

        $result = $this->runSync(['--agent=claude']);

        self::assertSame(0, $result['exit'], $result['output']);
        self::assertFileExists($this->root . '/custom-claude/settings.json');
        self::assertFileExists($this->root . '/custom-claude/hooks/policy.php');
    }

    /**
     * @param list<string> $scriptNames
     */
    private function writeBundle(array $scriptNames = ['policy.php']): void
    {
        $bundleRoot = $this->root . '/docs/agents/claude-hooks';
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

        file_put_contents($bundleRoot . '/hooks.json', json_encode(['hooks' => $hooks], \JSON_PRETTY_PRINT));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function writeSettings(array $settings): void
    {
        if (!is_dir($this->root . '/.claude')) {
            mkdir($this->root . '/.claude', 0o775, true);
        }

        file_put_contents($this->root . '/.claude/settings.json', json_encode($settings, \JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, mixed>
     */
    private function readSettings(): array
    {
        $decoded = json_decode((string) file_get_contents($this->root . '/.claude/settings.json'), true);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
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
