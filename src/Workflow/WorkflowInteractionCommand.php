<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use Throwable;
use voku\AgentLoop\AgentGuidance\InteractionPreferenceStore;

/** Manages behavioral preferences for an explicitly identified host session. */
final readonly class WorkflowInteractionCommand
{
    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $directory = getenv('AGENT_LOOP_INTERACTION_DIR');
            if (!is_string($directory) || $directory === '') {
                throw new RuntimeException('Set AGENT_LOOP_INTERACTION_DIR to a host-owned preference directory.');
            }
            $session = $args[0] ?? '';
            $mode = $args[1] ?? '';
            $task = $args[2] ?? null;
            if (count($args) > 3 || trim($session) === '' || ($task !== null && trim($task) === '')) {
                throw new RuntimeException('Usage: workflow interaction <host-session-id> <collaborative|autonomous> [task-id].');
            }
            if (strlen($session) > 128 || preg_match('/\\A[A-Za-z0-9._-]+\\z/', $session) !== 1) {
                throw new RuntimeException('Host session identity must match [A-Za-z0-9._-]+ and be at most 128 bytes.');
            }
            if ($task !== null && $mode !== '--reuse') {
                $task = (new WorkflowTaskId($task))->value;
            }
            if ($mode === 'inherit' && $task === null) {
                throw new RuntimeException('A task identity is required to clear an override.');
            }
            $store = new InteractionPreferenceStore($directory);
            if ($mode === '--reuse') {
                if ($task === null) {
                    throw new RuntimeException('Provide the previous host session identity to reuse.');
                }
                $previous = $store->find($task);
                if ($previous === null) {
                    throw new RuntimeException('The previous host session preference is unavailable.');
                }
                $mode = $previous->defaultMode;
                $task = null;
            }
            if ($task === null) {
                $store->setDefault($session, $mode);
            } else {
                $store->update($session, $mode, $task);
            }
            echo "[OK] interaction preference saved; it grants no authority.\n";
            return 0;
        } catch (Throwable $exception) {
            fwrite(STDERR, '[FAIL] workflow interaction: ' . $exception->getMessage() . "\n");
            return 1;
        }
    }
}
