<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow\Transparency;

/**
 * The exact current-or-stale review report, its identity and its acknowledgement.
 *
 * `lifecycleStatus` is the workflow answer (`unacknowledged`, `stale`, ...);
 * `reportStatus` is the report's own verdict. They differ on purpose: a clean
 * report nobody has acknowledged is not a passed gate.
 */
final readonly class ReviewDetail
{
    /** @param list<ReviewFinding> $findings */
    private function __construct(
        public bool $exists,
        public bool $invalid,
        public ReviewCurrency $currency,
        public ?string $lifecycleStatus,
        public ?string $reportStatus,
        public ?int $contractRevision,
        public ?string $implementationSnapshot,
        public ?string $sha256,
        public ?string $acknowledgedBy,
        public ?string $acknowledgedAt,
        public array $findings,
        public string $path,
    ) {
    }

    public static function missing(string $path): self
    {
        return new self(false, false, ReviewCurrency::MISSING, null, null, null, null, null, null, null, [], $path);
    }

    public static function invalid(string $path): self
    {
        return new self(true, true, ReviewCurrency::INVALID, null, null, null, null, null, null, null, [], $path);
    }

    /** @param list<ReviewFinding> $findings */
    public static function present(
        ReviewCurrency $currency,
        string $lifecycleStatus,
        string $reportStatus,
        ?int $contractRevision,
        ?string $implementationSnapshot,
        string $sha256,
        ?string $acknowledgedBy,
        ?string $acknowledgedAt,
        array $findings,
        string $path,
    ): self {
        return new self(
            true,
            false,
            $currency,
            $lifecycleStatus,
            $reportStatus,
            $contractRevision,
            $implementationSnapshot,
            $sha256,
            $acknowledgedBy,
            $acknowledgedAt,
            $findings,
            $path,
        );
    }

    /** @return list<ReviewFinding> */
    public function findingsWithSeverity(string $severity): array
    {
        return array_values(array_filter(
            $this->findings,
            static fn (ReviewFinding $finding): bool => $finding->severity === $severity,
        ));
    }

    /**
     * @return array{
     *     exists: bool,
     *     invalid: bool,
     *     currency: string,
     *     status: string|null,
     *     report_status: string|null,
     *     contract_revision: int|null,
     *     implementation_snapshot: string|null,
     *     sha256: string|null,
     *     acknowledged_by: string|null,
     *     acknowledged_at: string|null,
     *     findings: list<array{id: string, severity: string, message: string, evidence: list<string>}>,
     *     path: string
     * }
     */
    public function toArray(): array
    {
        return [
            'exists' => $this->exists,
            'invalid' => $this->invalid,
            'currency' => $this->currency->value,
            'status' => $this->lifecycleStatus,
            'report_status' => $this->reportStatus,
            'contract_revision' => $this->contractRevision,
            'implementation_snapshot' => $this->implementationSnapshot,
            'sha256' => $this->sha256,
            'acknowledged_by' => $this->acknowledgedBy,
            'acknowledged_at' => $this->acknowledgedAt,
            'findings' => array_map(static fn (ReviewFinding $finding): array => $finding->toArray(), $this->findings),
            'path' => $this->path,
        ];
    }
}
