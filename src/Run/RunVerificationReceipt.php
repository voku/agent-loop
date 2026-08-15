<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

final readonly class RunVerificationReceipt
{
    /**
     * @param list<array{command: string, status: string, exit_code: int|null, executed_at: string|null, recorded_by: string|null, duration_ms: int|null}> $obligations
     */
    public function __construct(
        public string $runId,
        public string $taskId,
        public int $contractRevision,
        public string $contractSha256,
        public string $verdict,
        public array $obligations,
        public string $sourceSessionId,
        public string $verifiedAt,
        public string $path,
        public ?string $implementationSnapshot = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'schema_version' => $this->implementationSnapshot === null ? '1.0' : '1.1',
            'kind' => 'run_verification_receipt',
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'contract_revision' => $this->contractRevision,
            'contract_sha256' => $this->contractSha256,
            'verdict' => $this->verdict,
            'obligations' => $this->obligations,
            'source_session_id' => $this->sourceSessionId,
            'verified_at' => $this->verifiedAt,
        ];
        if ($this->implementationSnapshot !== null) {
            $data['implementation_snapshot'] = $this->implementationSnapshot;
        }

        return $data;
    }
}
