<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use voku\AgentLoop\AgentGuidance\InteractionPreference;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;
use voku\AgentLoop\Workflow\WorkflowInteractionCommand;

final class InteractionPreferenceTest extends TestCase
{
    public function testTaskOverrideDoesNotChangeSessionDefault(): void
    {
        $preference = new InteractionPreference('session-a', 'autonomous', ['task-a' => 'collaborative']);
        self::assertSame('collaborative', $preference->modeFor('task-a'));
        self::assertSame('autonomous', $preference->modeFor('task-b'));
        self::assertSame('autonomous', $preference->modeFor(null));
    }

    public function testMissingSessionIdentityIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InteractionPreference('', 'autonomous');
    }
    public function testPersistenceIsIsolatedByHostSession(): void
    {
        $directory = sys_get_temp_dir() . '/interaction-' . bin2hex(random_bytes(8));
        $store = new InteractionPreferenceStore($directory);
        try {
            $store->update('session-a', 'autonomous', null);
            $store->update('session-a', 'collaborative', 'task-a');
            $found = $store->find('session-a');
            self::assertNotNull($found);
            self::assertSame('collaborative', $found->modeFor('task-a'));
            self::assertSame('autonomous', $found->modeFor('task-b'));
            self::assertNull($store->find('session-b'));
            self::assertNull($store->find(''));
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
    public function testCommandSetsDefaultAndOverride(): void
    {
        $directory = sys_get_temp_dir() . '/interaction-command-' . bin2hex(random_bytes(8));
        putenv('AGENT_LOOP_INTERACTION_DIR=' . $directory);
        try {
            ob_start();
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['host', 'autonomous']));
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['host', 'collaborative', '1052']));
            self::assertSame('autonomous', (new InteractionPreferenceStore($directory))->bindSession('fresh-host')?->modeFor('1052'));
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['host', 'inherit', '1052']));
            self::assertSame('autonomous', (new InteractionPreferenceStore($directory))->find('host')?->modeFor('1052'));
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['host', 'collaborative', '1052']));
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['other-host', 'collaborative']));
            self::assertSame('autonomous', (new InteractionPreferenceStore($directory))->find('fresh-host')?->defaultMode);
            self::assertSame(0, (new WorkflowInteractionCommand())->run(['new-host', '--reuse', 'host']));
            self::assertSame('autonomous', (new InteractionPreferenceStore($directory))->bindSession('future-host')?->defaultMode);
            ob_end_clean();
            self::assertSame('autonomous', (new InteractionPreferenceStore($directory))->find('new-host')?->modeFor('1052'));
            $found = (new InteractionPreferenceStore($directory))->find('host');
            self::assertNotNull($found);
            self::assertSame('collaborative', $found->modeFor('1052'));
            self::assertSame('autonomous', $found->modeFor('TASK-2'));
        } finally {
            putenv('AGENT_LOOP_INTERACTION_DIR');
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
    public function testSymlinkedSessionDoesNotFallBackToHostDefault(): void
    {
        $directory = sys_get_temp_dir() . '/interaction-link-' . bin2hex(random_bytes(8));
        $store = new InteractionPreferenceStore($directory);
        $store->update("\0host-default", 'autonomous', null);
        $link = $directory . '/' . hash('sha256', 'host') . '.json';
        symlink($directory . '/missing', $link);
        try {
            $this->expectException(\RuntimeException::class);
            $store->bindSession('host');
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
    public function testMalformedSessionDoesNotInheritSharedDefault(): void
    {
        $directory = sys_get_temp_dir() . '/interaction-malformed-' . bin2hex(random_bytes(8));
        $store = new InteractionPreferenceStore($directory);
        $store->update("\0host-default", 'autonomous', null);
        file_put_contents($directory . '/' . hash('sha256', 'host') . '.json', '{');
        try {
            $this->expectException(\RuntimeException::class);
            $store->bindSession('host');
        } finally {
            foreach (glob($directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }
}
