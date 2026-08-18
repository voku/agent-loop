<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class ReviewAcknowledgement
{
    public function __construct(
        public string $runId,
        public string $taskId,
        public int $contractRevision,
        public string $implementationSnapshot,
        public string $reportSha256,
        public string $acknowledgedBy,
        public string $acknowledgedAt,
        public string $path,
    ) {
    }

    /** @return array{schema_version:string,kind:string,run_id:string,task_id:string,contract_revision:int,implementation_snapshot:string,report_sha256:string,acknowledged_by:string,acknowledged_at:string} */
    public function toArray(): array
    {
        return [
            'schema_version' => '1.0',
            'kind' => 'review_acknowledgement',
            'run_id' => $this->runId,
            'task_id' => $this->taskId,
            'contract_revision' => $this->contractRevision,
            'implementation_snapshot' => $this->implementationSnapshot,
            'report_sha256' => $this->reportSha256,
            'acknowledged_by' => $this->acknowledgedBy,
            'acknowledged_at' => $this->acknowledgedAt,
        ];
    }
}
