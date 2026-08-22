<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;

/** The fixed four-kind rename wire envelope after fail-closed decoding. */
final readonly class RenamePlanDocument
{
    /** @var array<string, string> */
    private const TARGET_PREFIX = [
        'method_rename_plan' => 'method:',
        'function_rename_plan' => 'function:',
        'class_rename_plan' => 'class:',
        'property_rename_plan' => 'property:',
    ];

    /**
     * @param list<RenamePlanEditEvidence> $edits
     * @param list<RenamePlanMoveEvidence> $moves
     */
    public function __construct(
        public string $type,
        public string $targetId,
        public RenamePlanProvenanceEvidence $provenance,
        public array $edits,
        public array $moves,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = self::string($data, 'type');
        $targetId = self::string($data, 'target_id');
        if (($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map rename plan contract version.');
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw new RuntimeException('Rename plan is not safe; no source was changed.');
        }
        self::requireEmptyEvidence($data, 'blind_spots');
        self::requireEmptyEvidence($data, 'stale_evidence');
        self::requireEmptyEvidence($data, 'blockers');

        $prefix = self::TARGET_PREFIX[$type] ?? null;
        if (!is_string($prefix)) {
            throw new RuntimeException('Unsupported agent-map rename plan type.');
        }
        if (!str_starts_with($targetId, $prefix)) {
            throw new RuntimeException('Rename plan target identity does not match its plan type.');
        }

        $rawProvenance = $data['provenance'] ?? null;
        if (!is_array($rawProvenance)) {
            throw new RuntimeException('Rename plan requires typed provenance evidence.');
        }
        $rawEdits = $data['edits'] ?? null;
        if (!is_array($rawEdits)) {
            throw new RuntimeException('Rename plan contains an invalid edit list.');
        }
        $rawMoves = $data['moves'] ?? [];
        if (!is_array($rawMoves)) {
            throw new RuntimeException('Rename plan contains an invalid move list.');
        }

        $edits = [];
        foreach ($rawEdits as $rawEdit) {
            if (!is_array($rawEdit)) {
                throw new RuntimeException('Rename plan contains an invalid edit.');
            }
            $edit = RenamePlanEditEvidence::fromArray($rawEdit);
            if ($edit->symbolId !== $targetId) {
                throw new RuntimeException('Rename plan edit is not bound to the declared target identity.');
            }
            $edits[] = $edit;
        }

        $moves = [];
        foreach ($rawMoves as $rawMove) {
            if (!is_array($rawMove)) {
                throw new RuntimeException('Class rename plan contains an invalid move.');
            }
            $moves[] = RenamePlanMoveEvidence::fromArray($rawMove);
        }
        if ($type !== 'class_rename_plan' && $moves !== []) {
            throw new RuntimeException('Only class_rename_plan may publish file moves.');
        }
        if ($edits === [] && $moves === []) {
            throw new RuntimeException('Safe rename plan contains neither edits nor moves.');
        }

        return new self(
            type: $type,
            targetId: $targetId,
            provenance: RenamePlanProvenanceEvidence::fromArray($rawProvenance),
            edits: $edits,
            moves: $moves,
        );
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Rename plan requires non-empty string ' . $key . '.');
        }

        return $value;
    }

    /** @param array<string, mixed> $data */
    private static function requireEmptyEvidence(array $data, string $key): void
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || $value !== []) {
            throw new RuntimeException('Safe rename plan requires empty ' . $key . ' evidence.');
        }
    }
}
