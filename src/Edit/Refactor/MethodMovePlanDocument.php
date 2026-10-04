<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Refactor;

use RuntimeException;
use voku\AgentMap\Index\AgentMapIndex;

/** Fail-closed decoder for released `method_move_plan@1.0`; destination choice remains Map-owned. */
final readonly class MethodMovePlanDocument implements EditMovePlanEvidence
{
    /**
     * @param list<RenamePlanEditEvidence> $edits
     */
    public function __construct(
        public string $type,
        public string $targetId,
        public string $sourceId,
        public string $destinationFqn,
        public RenamePlanProvenanceEvidence $provenance,
        public array $edits,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (($data['type'] ?? null) !== 'method_move_plan' || ($data['contract_version'] ?? null) !== '1.0') {
            throw new RuntimeException('Unsupported agent-map method move plan contract.');
        }
        if (($data['status'] ?? null) === 'review_required') {
            throw new RuntimeException('Method move plan requires explicit review; no source was changed.');
        }
        if (($data['status'] ?? null) !== 'safe') {
            throw new RuntimeException('Method move plan is not safe; no source was changed.');
        }

        foreach (['blind_spots', 'stale_evidence', 'blockers'] as $field) {
            $value = $data[$field] ?? null;
            if (!is_array($value)) {
                throw new RuntimeException('Method move plan requires ' . $field . ' list evidence.');
            }
            if ($value !== []) {
                throw new RuntimeException('Safe method move plan requires empty ' . $field . ' evidence.');
            }
        }

        $ownerDependencies = $data['owner_dependencies'] ?? null;
        if (!is_array($ownerDependencies)) {
            throw new RuntimeException('Method move plan requires owner_dependencies list evidence.');
        }
        if ($ownerDependencies !== []) {
            throw new RuntimeException('Safe method move plan requires empty owner_dependencies evidence.');
        }

        $notObservable = $data['not_observable'] ?? null;
        if (!is_array($notObservable) || !array_is_list($notObservable)) {
            throw new RuntimeException('Method move plan requires not_observable list evidence.');
        }
        foreach ($notObservable as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new RuntimeException('Method move plan contains invalid not_observable evidence.');
            }
        }

        $targetId = self::string($data, 'target_id');
        $sourceId = self::string($data, 'source_id');
        if ($sourceId !== $targetId) {
            throw new RuntimeException('Method move source identity must exactly match its target identity.');
        }
        if (!str_starts_with($targetId, 'method:')) {
            throw new RuntimeException('Method move target identity must use the method: prefix.');
        }

        $methodIdentity = substr($targetId, strlen('method:'));
        $separator = strrpos($methodIdentity, '::');
        if ($separator === false || $separator === 0 || $separator === strlen($methodIdentity) - 2) {
            throw new RuntimeException('Method move target identity is malformed.');
        }
        $sourceFqn = substr($methodIdentity, 0, $separator);
        $methodName = substr($methodIdentity, $separator + 2);

        $destinationFqn = self::string($data, 'destination_fqn');
        if ($destinationFqn !== ltrim($destinationFqn, '\\') || str_contains($destinationFqn, '::')) {
            throw new RuntimeException('Method move destination must be an exact class identity.');
        }
        if (strcasecmp($sourceFqn, $destinationFqn) === 0) {
            throw new RuntimeException('Method move destination must differ from the source owner.');
        }

        $rawProvenance = $data['provenance'] ?? null;
        if (!is_array($rawProvenance)) {
            throw new RuntimeException('Method move plan requires typed provenance evidence.');
        }

        $rawEdits = $data['edits'] ?? null;
        if (!is_array($rawEdits) || !array_is_list($rawEdits) || count($rawEdits) < 2) {
            throw new RuntimeException('Safe method move plan requires at least declaration removal and insertion edits.');
        }

        if (isset($data['moves']) && (!is_array($data['moves']) || $data['moves'] !== [])) {
            throw new RuntimeException('method_move_plan@1.0 may not publish file moves.');
        }

        $edits = [];
        $removals = 0;
        $insertions = 0;
        $destinationMethodId = $destinationFqn . '::' . $methodName;

        foreach ($rawEdits as $rawEdit) {
            if (!is_array($rawEdit)) {
                throw new RuntimeException('Method move plan contains an invalid edit.');
            }

            $edit = RenamePlanEditEvidence::fromArray($rawEdit, true);
            if ($edit->resolution !== 'phpstan_resolved') {
                throw new RuntimeException('Method move plan edits require phpstan_resolved evidence.');
            }

            switch ($edit->role) {
                case 'method_declaration_removal':
                    ++$removals;
                    if ($edit->symbolId !== $targetId || $edit->replacement !== '') {
                        throw new RuntimeException('Method move removal edit is not bound to the exact source method identity.');
                    }
                    break;

                case 'method_declaration_insertion':
                    ++$insertions;
                    if ($edit->symbolId !== $destinationMethodId || $edit->replacement === '') {
                        throw new RuntimeException('Method move insertion edit is not bound to the exact destination method identity.');
                    }
                    break;

                case 'static_call_owner_rewrite':
                    if ($edit->symbolId !== $targetId || $edit->replacement === '') {
                        throw new RuntimeException('Method move call-site edit is not bound to the exact source method identity.');
                    }
                    break;

                default:
                    throw new RuntimeException('Unsupported method move edit role: ' . $edit->role . '.');
            }

            $edits[] = $edit;
        }

        if ($removals !== 1 || $insertions !== 1) {
            throw new RuntimeException('Safe method move plan requires exactly one declaration removal and one declaration insertion.');
        }

        return new self(
            type: 'method_move_plan',
            targetId: $targetId,
            sourceId: $sourceId,
            destinationFqn: $destinationFqn,
            provenance: RenamePlanProvenanceEvidence::fromArray($rawProvenance),
            edits: $edits,
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
        return true;
    }

    /** @return list<RenamePlanEditEvidence> */
    public function edits(): array
    {
        return $this->edits;
    }

    /** @return list<RenamePlanMoveEvidence> */
    public function moves(): array
    {
        return [];
    }

    public function assertMatches(AgentMapIndex $map): void
    {
        $this->provenance->assertMatches($map, true);
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Method move plan requires non-empty string ' . $key . '.');
        }

        return $value;
    }
}
