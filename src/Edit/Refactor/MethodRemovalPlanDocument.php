<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;

/** Fail-closed wire envelope for agent-map method_removal_plan@1.0. */
final readonly class MethodRemovalPlanDocument
{
    /** @param list<MethodRemovalPlanEditEvidence> $edits */
    public function __construct(
        public string $targetId,
        public MethodRemovalPlanProvenanceEvidence $provenance,
        public array $edits,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'method_removal_plan') {
            throw new RuntimeException('Unsupported agent-map method removal plan type.');
        }
        if (($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map method removal plan contract version.');
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw new RuntimeException('Method removal plan is not safe; no source was changed.');
        }

        self::requireEmptyList($data, 'stale_evidence', 'Method removal plan contains stale evidence; rebuild the map and re-plan before applying.');
        self::requireEmptyList($data, 'blockers', 'Method removal plan has semantic blockers; no source was changed.');
        self::requireEmptyList($data, 'blind_spots', 'Method removal plan requires explicit review; no source was changed.');

        $notObservable = $data['not_observable'] ?? null;
        if (!is_array($notObservable) || !array_is_list($notObservable)) {
            throw new RuntimeException('Method removal plan requires not_observable list evidence.');
        }
        foreach ($notObservable as $boundary) {
            if (!is_string($boundary) || trim($boundary) === '') {
                throw new RuntimeException('Method removal plan contains invalid not_observable evidence.');
            }
        }

        $targetId = self::string($data, 'target_id');
        if (!str_starts_with($targetId, 'method:')) {
            throw new RuntimeException('Method removal plan target identity must be a method.');
        }

        $rawProvenance = $data['provenance'] ?? null;
        if (!is_array($rawProvenance)) {
            throw new RuntimeException('Method removal plan requires typed provenance evidence.');
        }
        $rawEdits = $data['edits'] ?? null;
        if (!is_array($rawEdits) || !array_is_list($rawEdits) || $rawEdits === []) {
            throw new RuntimeException('Safe method removal plan requires at least one exact deletion edit.');
        }
        $moves = $data['moves'] ?? [];
        if (!is_array($moves) || $moves !== []) {
            throw new RuntimeException('Method removal plans cannot publish file moves.');
        }

        $edits = [];
        foreach ($rawEdits as $rawEdit) {
            if (!is_array($rawEdit)) {
                throw new RuntimeException('Method removal plan contains an invalid edit.');
            }
            $edit = MethodRemovalPlanEditEvidence::fromArray($rawEdit);
            if ($edit->symbolId !== $targetId) {
                throw new RuntimeException('Method removal edit is not bound to the declared target identity.');
            }
            $edits[] = $edit;
        }

        return new self(
            targetId: $targetId,
            provenance: MethodRemovalPlanProvenanceEvidence::fromArray($rawProvenance),
            edits: $edits,
        );
    }

    /** @param array<string, mixed> $data */
    private static function requireEmptyList(array $data, string $key, string $message): void
    {
        $value = $data[$key] ?? null;
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Method removal plan requires ' . $key . ' list evidence.');
        }
        if ($value !== []) {
            throw new RuntimeException($message);
        }
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Method removal plan requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
