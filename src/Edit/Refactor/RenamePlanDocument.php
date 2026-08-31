<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/** The fixed Map 0.9 rename wire envelope after fail-closed decoding. */
final readonly class RenamePlanDocument implements EditMovePlanEvidence
{
    /** @var array<string, string> */
    private const TARGET_PREFIX = [
        'method_rename_plan' => 'method:',
        'function_rename_plan' => 'function:',
        'class_rename_plan' => 'class:',
        'property_rename_plan' => 'property:',
        'class_constant_rename_plan' => 'class_constant:',
        'parameter_rename_plan' => 'method:',
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

        $staleEvidence = $data['stale_evidence'] ?? null;
        if (!is_array($staleEvidence)) {
            throw new RuntimeException('Rename plan requires stale_evidence list evidence.');
        }
        if ($staleEvidence !== []) {
            throw new RuntimeException('Rename plan contains stale evidence; rebuild the map and re-plan before applying.');
        }

        $blockers = $data['blockers'] ?? null;
        if (!is_array($blockers)) {
            throw new RuntimeException('Rename plan requires blockers list evidence.');
        }
        if ($blockers !== []) {
            throw new RuntimeException('Rename plan has semantic blockers; no source was changed.');
        }

        $status = $data['status'] ?? null;
        if ($status === 'review_required') {
            throw new RuntimeException('Rename plan requires explicit review; no source was changed.');
        }
        if ($status !== 'safe') {
            throw new RuntimeException('Rename plan is not safe; no source was changed.');
        }

        $blindSpots = $data['blind_spots'] ?? null;
        if (!is_array($blindSpots) || $blindSpots !== []) {
            throw new RuntimeException('Safe rename plan requires empty blind_spots evidence.');
        }

        $prefix = self::TARGET_PREFIX[$type] ?? null;
        if (!is_string($prefix)) {
            throw new RuntimeException('Unsupported agent-map rename plan type.');
        }
        if (!str_starts_with($targetId, $prefix)) {
            throw new RuntimeException('Rename plan target identity does not match its plan type.');
        }

        $allowedEditSymbols = [$targetId => true];
        if ($type === 'parameter_rename_plan') {
            $family = $data['family'] ?? null;
            if (!is_array($family) || $family === []) {
                throw new RuntimeException('Parameter rename plan requires non-empty method-family evidence.');
            }
            $allowedEditSymbols = [];
            foreach ($family as $member) {
                if (!is_string($member) || !str_starts_with($member, 'method:')) {
                    throw new RuntimeException('Parameter rename plan contains invalid method-family evidence.');
                }
                $allowedEditSymbols[$member] = true;
            }
            if (!isset($allowedEditSymbols[$targetId])) {
                throw new RuntimeException('Parameter rename target is not part of its declared method family.');
            }
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
            if (!self::editSymbolIsAllowed($edit->symbolId, $allowedEditSymbols)) {
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

    /** Returns the stable owner-published rename plan type. */
    public function planType(): string
    {
        return $this->type;
    }

    /** Returns the exact owner-published target identity. */
    public function targetId(): string
    {
        return $this->targetId;
    }

    /** Reports whether this rename family requires PHPStan-backed Map evidence. */
    public function requiresPhpStan(): bool
    {
        return in_array($this->type, [
            'function_rename_plan',
            'method_rename_plan',
            'parameter_rename_plan',
            'property_rename_plan',
        ], true);
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

    /** Revalidates the frozen rename provenance against the current Map. */
    public function assertMatches(AgentMapIndex $map): void
    {
        $this->provenance->assertMatches($map, $this->requiresPhpStan());
    }

    /** @param array<string, true> $allowed */
    private static function editSymbolIsAllowed(string $symbolId, array $allowed): bool
    {
        $members = explode(',', $symbolId);
        foreach ($members as $member) {
            if ($member === '' || !isset($allowed[$member])) {
                return false;
            }
        }

        return true;
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
}
