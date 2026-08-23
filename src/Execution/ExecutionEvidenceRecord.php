<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use InvalidArgumentException;

final readonly class ExecutionEvidenceRecord
{
    public string $reference;
    public string $recordedAt;

    public function __construct(
        string $reference,
        public ExecutionEvidenceClaim $claim,
        string $recordedAt,
    ) {
        $this->reference = trim($reference);
        $this->recordedAt = trim($recordedAt);

        if (preg_match('/^execution-evidence:sha256:[a-f0-9]{64}$/', $this->reference) !== 1) {
            throw new InvalidArgumentException('Execution evidence record requires an owner evidence reference.');
        }
        if ($this->recordedAt === '') {
            throw new InvalidArgumentException('Execution evidence record requires recorded_at.');
        }
    }

    /** @return array<string, int|string> */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'execution_evidence',
            'reference' => $this->reference,
            ...$this->claim->toArray(),
            'recorded_at' => $this->recordedAt,
        ];
    }
}
