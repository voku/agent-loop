<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;

/** Validates identity fields that become trust-sensitive when a rename plan is loaded from disk. */
final readonly class RenamePlanDocumentGuard
{
    /** @var array<string, string> */
    private const TARGET_PREFIX = [
        'method_rename_plan' => 'method:',
        'function_rename_plan' => 'function:',
        'class_rename_plan' => 'class:',
        'property_rename_plan' => 'property:',
    ];

    /** @param array<string, mixed> $plan */
    public function validate(array $plan): void
    {
        $type = $plan['type'] ?? null;
        $targetId = $plan['target_id'] ?? null;
        if (!is_string($type) || !isset(self::TARGET_PREFIX[$type])) {
            throw new RuntimeException('Unsupported agent-map rename plan type.');
        }
        if (!is_string($targetId) || !str_starts_with($targetId, self::TARGET_PREFIX[$type])) {
            throw new RuntimeException('Rename plan target identity does not match its plan type.');
        }

        $edits = $plan['edits'] ?? null;
        if (!is_array($edits)) {
            throw new RuntimeException('Rename plan contains an invalid edit list.');
        }
        foreach ($edits as $edit) {
            if (!is_array($edit)) {
                throw new RuntimeException('Rename plan contains an invalid edit.');
            }
            if (($edit['symbol_id'] ?? null) !== $targetId) {
                throw new RuntimeException('Rename plan edit is not bound to the declared target identity.');
            }
            foreach (['role', 'resolution'] as $field) {
                if (!is_string($edit[$field] ?? null) || trim($edit[$field]) === '') {
                    throw new RuntimeException('Rename plan edit is missing ' . $field . ' evidence.');
                }
            }
        }

        $moves = $plan['moves'] ?? [];
        if (!is_array($moves)) {
            throw new RuntimeException('Rename plan contains an invalid move list.');
        }
        if ($type !== 'class_rename_plan' && $moves !== []) {
            throw new RuntimeException('Only class_rename_plan may publish file moves.');
        }
        foreach ($moves as $move) {
            if (!is_array($move)) {
                throw new RuntimeException('Class rename plan contains an invalid move.');
            }
            foreach (['from_path', 'to_path', 'source_sha256', 'reason'] as $field) {
                if (!is_string($move[$field] ?? null) || trim($move[$field]) === '') {
                    throw new RuntimeException('Class rename move is missing ' . $field . ' evidence.');
                }
            }
        }
    }
}
