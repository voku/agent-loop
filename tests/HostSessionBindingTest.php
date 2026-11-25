<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\AgentGuidance\HostSessionBinding;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\Workflow\WorkflowInteractionCommand;
use voku\AgentLoop\Workflow\WorkflowPresentationLanguage;

final class HostSessionBindingTest extends TestCase
{
    private const array VARIABLES = ['AGENT_LOOP_HOST_SESSION_ID', 'CLAUDE_CODE_SESSION_ID', 'AGENT_LOOP_INTERACTION_DIR', 'XDG_STATE_HOME', 'HOME'];

    /** @var array<string, string|false> */
    private array $previous = [];
    private string $root;

    protected function setUp(): void
    {
        foreach (self::VARIABLES as $name) {
            $this->previous[$name] = getenv($name);
            putenv($name);
        }
        $this->root = sys_get_temp_dir() . '/host-session-' . bin2hex(random_bytes(8));
        putenv('XDG_STATE_HOME=' . $this->root . '/state');
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testNoHostVariableIsUnboundAndNoIdentityIsMadeUp(): void
    {
        $binding = HostSessionBinding::fromEnvironment();
        self::assertSame(HostSessionBinding::UNBOUND, $binding->state);
        self::assertNull($binding->sessionId);
        self::assertNull($binding->source);
    }

    public function testClaudeCodeSessionBindsWithoutAnySetup(): void
    {
        putenv('CLAUDE_CODE_SESSION_ID=3987881d-d5dd-4c8a-83cb-123cd14bcadf');
        $binding = HostSessionBinding::fromEnvironment();
        self::assertSame(HostSessionBinding::BOUND, $binding->state);
        self::assertSame('3987881d-d5dd-4c8a-83cb-123cd14bcadf', $binding->sessionId);
        self::assertSame('CLAUDE_CODE_SESSION_ID', $binding->source);
        self::assertSame($this->root . '/state/agent-loop/interaction', $binding->directory);
    }

    public function testExplicitIdentityWinsOverTheHostVariable(): void
    {
        putenv('CLAUDE_CODE_SESSION_ID=claude-session');
        putenv('AGENT_LOOP_HOST_SESSION_ID=explicit-session');
        $binding = HostSessionBinding::fromEnvironment();
        self::assertSame('explicit-session', $binding->sessionId);
        self::assertSame('AGENT_LOOP_HOST_SESSION_ID', $binding->source);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentities(): iterable
    {
        yield 'path' => ['../host'];
        yield 'space' => ['host a'];
        yield 'option' => ['--language'];
        yield 'trailing newline' => ["host\n"];
        yield 'too long' => [str_repeat('a', 129)];
    }

    #[DataProvider('invalidIdentities')]
    public function testInvalidExplicitIdentityNeverFallsBackToAnotherSession(string $identity): void
    {
        putenv('CLAUDE_CODE_SESSION_ID=claude-session');
        putenv('AGENT_LOOP_HOST_SESSION_ID=' . $identity);
        $binding = HostSessionBinding::fromEnvironment();
        self::assertSame(HostSessionBinding::INVALID, $binding->state);
        self::assertSame('AGENT_LOOP_HOST_SESSION_ID', $binding->source);
        self::assertNull($binding->sessionId);
    }

    public function testPreferenceDirectoryPrecedence(): void
    {
        putenv('HOME=/home/dev');
        self::assertSame($this->root . '/state/agent-loop/interaction', HostSessionBinding::preferenceDirectory());
        putenv('XDG_STATE_HOME=relative');
        self::assertSame('/home/dev/.local/state/agent-loop/interaction', HostSessionBinding::preferenceDirectory());
        putenv('AGENT_LOOP_INTERACTION_DIR=/configured');
        self::assertSame('/configured', HostSessionBinding::preferenceDirectory());
        putenv('AGENT_LOOP_INTERACTION_DIR');
        putenv('HOME');
        self::assertNull(HostSessionBinding::preferenceDirectory());
    }

    public function testReadingThePreferenceInheritsTheSharedDefaultWithoutWriting(): void
    {
        putenv('CLAUDE_CODE_SESSION_ID=fresh');
        $directory = $this->root . '/state/agent-loop/interaction';
        $unset = HostSessionBinding::fromEnvironment();
        self::assertNull($unset->preference());
        self::assertDirectoryDoesNotExist($directory, 'Reading must not create the preference directory.');

        (new InteractionPreferenceStore($directory))->setDefault('earlier', 'autonomous');
        $before = scandir($directory);
        $inherited = HostSessionBinding::fromEnvironment()->preference();
        self::assertNotNull($inherited);
        self::assertSame('autonomous', $inherited->defaultMode);
        self::assertSame($before, scandir($directory));
        self::assertNull((new InteractionPreferenceStore($directory))->find('fresh'));
    }

    public function testUnreadableStoreIsAnErrorNotAMissingPreference(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root ignores directory permissions.');
        }
        $directory = $this->root . '/state/agent-loop/interaction';
        (new InteractionPreferenceStore($directory))->setDefault('fresh', 'autonomous');
        putenv('CLAUDE_CODE_SESSION_ID=fresh');
        $binding = HostSessionBinding::fromEnvironment();
        $file = $directory . '/' . hash('sha256', 'fresh') . '.json';
        $restrict = [[$file, 0o000, 0o600], [$directory, 0o000, 0o700], [dirname($directory), 0o000, 0o700]];
        foreach ($restrict as [$path, $closed, $open]) {
            chmod($path, $closed);
            try {
                $binding->preference();
                self::fail('An unreadable store must not look like a missing preference.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('Cannot read interaction preference', $exception->getMessage());
            } finally {
                chmod($path, $open);
            }
        }
    }

    public function testAFileInPlaceOfTheDirectoryIsAnErrorNotAMissingPreference(): void
    {
        mkdir($this->root . '/state/agent-loop', 0o700, true);
        file_put_contents($this->root . '/state/agent-loop/interaction', '');
        putenv('CLAUDE_CODE_SESSION_ID=fresh');
        $this->expectExceptionMessage('Cannot read interaction preference');
        HostSessionBinding::fromEnvironment()->preference();
    }

    public function testSessionLanguageFollowsTheHostSessionVariable(): void
    {
        $directory = $this->root . '/state/agent-loop/interaction';
        $store = new InteractionPreferenceStore($directory);
        $store->setDefault('fresh', 'autonomous');
        $store->setLanguage('fresh', 'de-de');
        putenv('CLAUDE_CODE_SESSION_ID=fresh');
        self::assertSame('de-de', WorkflowPresentationLanguage::resolve($this->root)->language);
    }

    public function testTaskOverrideOfAnInheritedSessionBindsItFirst(): void
    {
        $directory = $this->root . '/state/agent-loop/interaction';
        $store = new InteractionPreferenceStore($directory);
        $store->setDefault('earlier', 'autonomous');

        ob_start();
        try {
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['fresh', 'collaborative', 'TASK-1']));
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['other', '--language=de-DE']));
        } finally {
            ob_end_clean();
        }
        self::assertSame('collaborative', $store->find('fresh')?->modeFor('TASK-1'));
        self::assertSame('autonomous', $store->find('fresh')->modeFor('TASK-2'));
        self::assertSame('de-de', $store->find('other')?->language);
        self::assertSame('autonomous', $store->find('earlier')?->modeFor('TASK-1'));

        try {
            $store->update('typo', 'bogus', 'TASK-1');
            self::fail('An unknown mode must be rejected.');
        } catch (InvalidArgumentException) {
            self::assertNull($store->find('typo'), 'A rejected override must not bind the session.');
        }
    }

    public function testRejectedTaskOverrideCommandLeavesTheSessionUnbound(): void
    {
        $directory = $this->root . '/state/agent-loop/interaction';
        $store = new InteractionPreferenceStore($directory);
        $store->setDefault('earlier', 'autonomous');

        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/bin/agent-loop', 'workflow', 'interaction', 'typo', 'bogus', 'TASK-1'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['AGENT_LOOP_INTERACTION_DIR' => $directory, 'PATH' => (string) getenv('PATH')],
        );
        self::assertIsResource($process);
        stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        self::assertSame(1, proc_close($process), $error);
        self::assertStringContainsString('Interaction mode must be collaborative or autonomous', $error);
        self::assertNull($store->find('typo'));
        self::assertSame('autonomous', $store->effective('typo')?->defaultMode);
    }
}
