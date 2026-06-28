<?php

declare(strict_types=1);

namespace voku\AgentLoop\Review;

final readonly class ReviewReport
{
    public const int VERSION = 1;

    /**
     * @param list<BlindSpotFinding> $findings
     */
    public function __construct(
        public string $taskId,
        public array $findings,
    ) {
    }

    public function status(): string
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === ReviewSeverity::FAIL) {
                return 'fail';
            }
        }

        foreach ($this->findings as $finding) {
            if ($finding->severity === ReviewSeverity::WARN) {
                return 'warn';
            }
        }

        return 'ok';
    }

    /**
     * @return array{version: int, task_id: string, status: string, findings: list<array{id: string, severity: string, message: string, evidence: list<string>}>}
     */
    public function toArray(): array
    {
        return [
            'version' => self::VERSION,
            'task_id' => $this->taskId,
            'status' => $this->status(),
            'findings' => array_map(static fn (BlindSpotFinding $finding): array => $finding->toArray(), $this->findings),
        ];
    }
}
