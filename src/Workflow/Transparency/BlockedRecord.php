<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use voku\AgentLoop\Workflow\ExecutionContractStore;

/**
 * A durable BLOCKED/REJECTED execution-contract result.
 *
 * Owner evidence: a human recorded that this task cannot proceed as specified,
 * and named the minimum Contract change that would unblock it. Nothing here is
 * derived from the absence of progress.
 */
final readonly class BlockedRecord
{
    /** @param list<string> $evidence */
    public function __construct(
        public string $state,
        public ?string $reason,
        public array $evidence,
        public ?string $affectedConstraint,
        public ?string $minimumContractChange,
    ) {
    }

    public static function find(string $rootPath, string $taskId): ?self
    {
        $inspection = (new ExecutionContractStore($rootPath))->inspect($taskId);
        $state = $inspection['state'] ?? null;
        if (!is_string($state) || !in_array($state, ['blocked', 'rejected'], true)) {
            return null;
        }

        return new self(
            state: $state,
            reason: self::stringOrNull($inspection['reason'] ?? null),
            evidence: self::evidenceOf($inspection['evidence'] ?? null),
            affectedConstraint: self::stringOrNull($inspection['affected_constraint'] ?? null),
            minimumContractChange: self::stringOrNull($inspection['minimum_contract_change'] ?? null),
        );
    }

    /**
     * @return array{
     *     state: string,
     *     reason: string|null,
     *     evidence: list<string>,
     *     affected_constraint: string|null,
     *     minimum_contract_change: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'state' => $this->state,
            'reason' => $this->reason,
            'evidence' => $this->evidence,
            'affected_constraint' => $this->affectedConstraint,
            'minimum_contract_change' => $this->minimumContractChange,
        ];
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return list<string> */
    private static function evidenceOf(mixed $value): array
    {
        return array_values(array_filter(
            is_array($value) ? $value : [],
            static fn (mixed $item): bool => is_string($item),
        ));
    }
}
