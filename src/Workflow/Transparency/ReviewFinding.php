<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

use voku\AgentRecallCompiler\Review\BlindSpotFinding;

/**
 * One finding from the exact persisted review report.
 *
 * Review evidence. A `FAIL` finding is not a rejection and an `INFO` finding is
 * not an approval. The lifecycle only advances when an acknowledgement is bound
 * to this exact report SHA-256; ordinary hosts may record that acknowledgement
 * through the acting agent after the task Contract has already been approved.
 */
final readonly class ReviewFinding
{
    /** @param list<string> $evidence */
    public function __construct(
        public string $id,
        public string $severity,
        public string $message,
        public array $evidence,
    ) {
    }

    public static function fromOwner(BlindSpotFinding $finding): self
    {
        return new self($finding->id, $finding->severity->value, $finding->message, $finding->evidence);
    }

    /** @return array{id: string, severity: string, message: string, evidence: list<string>} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'severity' => $this->severity,
            'message' => $this->message,
            'evidence' => $this->evidence,
        ];
    }
}
