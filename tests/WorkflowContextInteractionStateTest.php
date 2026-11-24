<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\Workflow\WorkflowContextCommand;

/**
 * The workflow context projection (embedded by enter/finish) reports the host
 * session's interaction state so a host can tell a missing binding from a bound
 * session whose preference is unknown. This proves the projection only, not
 * that any real host presents the question exactly once.
 */
final class WorkflowContextInteractionStateTest extends TestCase
{
    private string $root;
    private string $directory;
    private string|false $previousDirectory;
    private string|false $previousSession;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->root = sys_get_temp_dir() . '/agent-loop-interaction-state-' . $suffix;
        $this->directory = sys_get_temp_dir() . '/agent-loop-interaction-prefs-' . $suffix;
        mkdir($this->root . '/.agent-loop', 0o775, true);
        $this->previousDirectory = getenv('AGENT_LOOP_INTERACTION_DIR');
        $this->previousSession = getenv('AGENT_LOOP_HOST_SESSION_ID');
        putenv('AGENT_LOOP_INTERACTION_DIR');
        putenv('AGENT_LOOP_HOST_SESSION_ID');
    }

    protected function tearDown(): void
    {
        putenv($this->previousDirectory === false ? 'AGENT_LOOP_INTERACTION_DIR' : 'AGENT_LOOP_INTERACTION_DIR=' . $this->previousDirectory);
        putenv($this->previousSession === false ? 'AGENT_LOOP_HOST_SESSION_ID' : 'AGENT_LOOP_HOST_SESSION_ID=' . $this->previousSession);
        exec('rm -rf ' . escapeshellarg($this->root) . ' ' . escapeshellarg($this->directory));
    }

    public function testWithoutSessionBindingTheStateIsUnboundAndNoQuestionOrIdentityIsFabricated(): void
    {
        foreach ([[null, null], ['host-1', null], [null, 'dir']] as [$session, $directory]) {
            putenv($session === null ? 'AGENT_LOOP_HOST_SESSION_ID' : 'AGENT_LOOP_HOST_SESSION_ID=' . $session);
            putenv($directory === null ? 'AGENT_LOOP_INTERACTION_DIR' : 'AGENT_LOOP_INTERACTION_DIR=' . $this->directory);

            $interaction = $this->interaction('TASK-1');

            self::assertSame('unbound', $interaction['host_session']['state']);
            self::assertNull($interaction['host_session']['id']);
            self::assertStringContainsString('AGENT_LOOP_HOST_SESSION_ID', (string) $interaction['host_session']['prerequisite']);
            self::assertSame('unknown', $interaction['preference']['state']);
            self::assertNull($interaction['question']);
            $this->assertExistingFieldsUnchanged($interaction);
        }
    }

    public function testAnUnsafeSessionIdIsInvalidAndNotEchoedAsAUsableIdentity(): void
    {
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->directory);
        foreach (['bad id; rm -rf /', str_repeat('a', 129), '../escape'] as $session) {
            putenv('AGENT_LOOP_HOST_SESSION_ID=' . $session);
            $interaction = $this->interaction('TASK-1');

            self::assertSame('invalid', $interaction['host_session']['state']);
            self::assertNull($interaction['host_session']['id']);
            self::assertNull($interaction['question']);
            self::assertStringNotContainsString($session, (string) json_encode($interaction));
        }
    }

    public function testAnUnreadablePreferenceIsUnavailableAndAsksNothing(): void
    {
        $this->bind('host-1');
        mkdir($this->directory, 0o700, true);
        file_put_contents($this->directory . '/' . hash('sha256', 'host-1') . '.json', '{not json');

        $interaction = $this->interaction('TASK-1');

        self::assertSame('bound', $interaction['host_session']['state']);
        self::assertSame('unavailable', $interaction['preference']['state']);
        self::assertNull($interaction['question']);
        $this->assertExistingFieldsUnchanged($interaction);
    }

    public function testBoundWithoutPreferenceAsksExactlyOneQuestionWithTheExistingCommands(): void
    {
        $this->bind('host-1');

        $interaction = $this->interaction('TASK-1');

        self::assertSame(['state' => 'bound', 'id' => 'host-1', 'prerequisite' => null], $interaction['host_session']);
        self::assertSame('unknown', $interaction['preference']['state']);
        $question = $interaction['question'];
        self::assertIsArray($question);
        self::assertSame('interaction_mode', $question['id']);
        self::assertSame('behavioral_preference_only', $question['authority']);
        self::assertSame(
            [
                'collaborative' => 'agent-loop workflow interaction host-1 collaborative',
                'autonomous' => 'agent-loop workflow interaction host-1 autonomous',
            ],
            array_column($question['choices'], 'command', 'id'),
        );
        $this->assertExistingFieldsUnchanged($interaction);
    }

    public function testAPreviousSharedDefaultIsOfferedAsReuseWithoutBeingApplied(): void
    {
        (new InteractionPreferenceStore($this->directory))->setDefault('host-old', 'autonomous');
        $this->bind('host-new');

        $interaction = $this->interaction('TASK-1');

        self::assertSame('unknown', $interaction['preference']['state'], 'Reading context never binds the shared default.');
        $choices = array_column($interaction['question']['choices'] ?? [], 'command', 'id');
        self::assertSame('agent-loop workflow interaction host-new autonomous', $choices['reuse'] ?? null);
        self::assertNull((new InteractionPreferenceStore($this->directory))->find('host-new'));
    }

    public function testAKnownDefaultOrTaskOverrideIsReportedWithItsSourceAndAsksNothing(): void
    {
        $store = new InteractionPreferenceStore($this->directory);
        $store->update('host-1', 'collaborative', null);
        $store->update('host-1', 'autonomous', 'TASK-1');
        $this->bind('host-1');

        $override = $this->interaction('TASK-1');
        self::assertSame(['state' => 'known', 'mode' => 'autonomous', 'source' => 'task_override'], $override['preference']);
        self::assertNull($override['question']);

        $other = $this->interaction('TASK-2');
        self::assertSame(['state' => 'known', 'mode' => 'collaborative', 'source' => 'session_default'], $other['preference'], 'An override for another task does not apply.');
        self::assertNull($other['question']);
        $this->assertExistingFieldsUnchanged($other);
    }

    public function testRepeatedReadsKeepExposingTheUnresolvedQuestionUntilAnswered(): void
    {
        $this->bind('host-1');
        self::assertNotNull($this->interaction('TASK-1')['question']);
        self::assertNotNull($this->interaction('TASK-1')['question'], 'The projection is stateless; presenting it once is host behavior.');

        (new InteractionPreferenceStore($this->directory))->update('host-1', 'collaborative', null);
        self::assertNull($this->interaction('TASK-1')['question']);
    }

    private function bind(string $session): void
    {
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $this->directory);
        putenv('AGENT_LOOP_HOST_SESSION_ID=' . $session);
    }

    /** @return array<string, mixed> */
    private function interaction(string $taskId): array
    {
        return (new WorkflowContextCommand($this->root))->build($taskId, 120, 12000)['interaction'];
    }

    /** @param array<string, mixed> $interaction */
    private function assertExistingFieldsUnchanged(array $interaction): void
    {
        self::assertSame('ask', $interaction['human_explanations']);
        self::assertSame('ask', $interaction['interactive_behavior']);
        self::assertSame('skip', $interaction['unattended_behavior']);
        self::assertSame('human_required', $interaction['authority_bearing_decisions']);
    }
}
