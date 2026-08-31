<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/** Fail-closed decoder for `class_move_plan@1.0`; destination choice remains Map-owned. */
final readonly class ClassMovePlanDocument implements EditMovePlanEvidence
{
    /**
     * @param list<RenamePlanEditEvidence> $edits
     * @param list<RenamePlanMoveEvidence> $moves
     */
    public function __construct(
        public string $type,
        public string $targetId,
        public ClassMovePlanProvenanceEvidence $provenance,
        public array $edits,
        public array $moves,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = self::string($data, 'type');
        if ($type !== 'class_move_plan' || ($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map class move plan contract.');
        }
        if (($data['status'] ?? null) === 'review_required') {
            throw new RuntimeException('Class move plan requires explicit review; no source was changed.');
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw new RuntimeException('Class move plan is not safe; no source was changed.');
        }
        foreach (['blind_spots', 'stale_evidence', 'blockers'] as $field) {
            $value = $data[$field] ?? null;
            if (!is_array($value)) {
                throw new RuntimeException('Class move plan requires ' . $field . ' list evidence.');
            }
            if ($value !== []) {
                throw new RuntimeException('Safe class move plan requires empty ' . $field . ' evidence.');
            }
        }

        $targetId = self::string($data, 'target_id');
        if (!str_starts_with($targetId, 'class:')) {
            throw new RuntimeException('Class move target identity must use the class: prefix.');
        }
        $sourceFqn = self::string($data, 'source_fqn');
        $destinationFqn = self::string($data, 'destination_fqn');
        if ($targetId !== 'class:' . ltrim($sourceFqn, '\\')) {
            throw new RuntimeException('Class move target identity does not match its source class identity.');
        }
        if (strcasecmp($sourceFqn, $destinationFqn) === 0) {
            throw new RuntimeException('Class move destination must differ from the source identity.');
        }

        $rawProvenance = $data['provenance'] ?? null;
        if (!is_array($rawProvenance)) {
            throw new RuntimeException('Class move plan requires typed provenance evidence.');
        }

        $rawEdits = $data['edits'] ?? null;
        if (!is_array($rawEdits)) {
            throw new RuntimeException('Class move plan contains an invalid edit list.');
        }
        $edits = [];
        foreach ($rawEdits as $rawEdit) {
            if (!is_array($rawEdit)) {
                throw new RuntimeException('Class move plan contains an invalid edit.');
            }
            $edit = RenamePlanEditEvidence::fromArray($rawEdit);
            if ($edit->symbolId !== $targetId) {
                throw new RuntimeException('Class move plan edit is not bound to its source class identity.');
            }
            $edits[] = $edit;
        }

        $rawMoves = $data['moves'] ?? null;
        if (!is_array($rawMoves) || count($rawMoves) !== 1 || !is_array($rawMoves[0])) {
            throw new RuntimeException('Safe class move plan requires exactly one preconditioned file move.');
        }
        if (($rawMoves[0]['destination_must_be_absent'] ?? null) !== true) {
            throw new RuntimeException('Class move destination must be explicitly required to be absent.');
        }
        $moves = [RenamePlanMoveEvidence::fromArray($rawMoves[0])];

        if ($edits === []) {
            throw new RuntimeException('Safe class move plan requires at least one exact source edit.');
        }

        return new self(
            type: $type,
            targetId: $targetId,
            provenance: ClassMovePlanProvenanceEvidence::fromArray($rawProvenance),
            edits: $edits,
            moves: $moves,
        );
    }

    public function planType(): string
    {
        return $this->type;
    }

    public function targetId(): string
    {
        return $this->targetId;
    }

    public function requiresPhpStan(): bool
    {
        return false;
    }

    /** @return list<RenamePlanEditEvidence> */
    public function edits(): array
    {
        return $this->edits;
    }

    /** @return list<RenamePlanMoveEvidence> */
    public function moves(): array
    {
        return $this->moves;
    }

    public function assertMatches(AgentMapIndex $map): void
    {
        $this->provenance->assertMatches($map);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Class move plan requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
