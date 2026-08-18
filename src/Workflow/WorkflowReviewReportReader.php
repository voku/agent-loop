<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentRecallCompiler\Review\ReviewReportReader;

/**
 * Workflow-shaped projection over agent-recall-compiler's typed report reader.
 *
 * The owner package validates report structure. agent-loop then checks whether
 * that exact persisted report is current for this Run/Contract/implementation
 * and whether an authority-bearing acknowledgement names its exact SHA-256.
 */
final readonly class WorkflowReviewReportReader
{
    public function __construct(private string $rootPath)
    {
    }

    public function absolutePath(string $taskId): string
    {
        return RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/reviews/' . $taskId . '.blindspots.json';
    }

    public function relativePath(string $taskId): string
    {
        return RecallOutputRoot::relativeTo($this->rootPath, $this->absolutePath($taskId));
    }

    /**
     * `status` is a lifecycle fact, not merely the report's internal verdict:
     * current `ok`/`warn` reports remain `unacknowledged` until the exact report
     * identity is acknowledged; a report bound to another implementation is `stale`.
     *
     * @return array{
     *   exists: bool,
     *   status: string|null,
     *   report_status: string|null,
     *   invalid: bool,
     *   contract_revision: int|null,
     *   implementation_snapshot: string|null,
     *   sha256: string|null,
     *   acknowledged_by: string|null
     * }
     */
    public function read(string $taskId): array
    {
        $outputDirectory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        try {
            $artifact = (new ReviewReportReader($this->rootPath))->read($taskId, $outputDirectory);
        } catch (Throwable) {
            return $this->invalid();
        }
        if ($artifact === null) {
            return $this->missing();
        }

        $reportStatus = $artifact->report->status();
        $status = $reportStatus;
        $acknowledgedBy = null;

        try {
            $contract = (new TaskContractStore($this->rootPath))->find($taskId);
            $run = (new GovernedRunStore($this->rootPath))->find($taskId);
            if (
                $contract !== null
                && $run !== null
                && $contract->status === TaskContract::APPROVED
                && $run->contractRevision === $contract->revision
            ) {
                $implementation = ImplementationSnapshot::capture($this->rootPath, $contract);
                $reportCurrent = $artifact->report->contractRevision === $contract->revision
                    && $artifact->report->implementationSnapshot !== null
                    && hash_equals($artifact->report->implementationSnapshot, $implementation->digest);

                if (!$reportCurrent) {
                    $status = 'stale';
                } elseif (in_array($reportStatus, ['ok', 'warn'], true)) {
                    $acknowledgement = (new ReviewAcknowledgementStore($this->rootPath))->find($taskId);
                    $acknowledged = $acknowledgement !== null
                        && $acknowledgement->runId === $run->runId
                        && $acknowledgement->contractRevision === $contract->revision
                        && hash_equals($acknowledgement->implementationSnapshot, $implementation->digest)
                        && hash_equals($acknowledgement->reportSha256, $artifact->sha256);
                    if ($acknowledged) {
                        $acknowledgedBy = $acknowledgement->acknowledgedBy;
                    } else {
                        $status = 'unacknowledged';
                    }
                }
            }
        } catch (Throwable) {
            return $this->invalid();
        }

        return [
            'exists' => true,
            'status' => $status,
            'report_status' => $reportStatus,
            'invalid' => false,
            'contract_revision' => $artifact->report->contractRevision,
            'implementation_snapshot' => $artifact->report->implementationSnapshot,
            'sha256' => $artifact->sha256,
            'acknowledged_by' => $acknowledgedBy,
        ];
    }

    /** @return array{exists:false,status:null,report_status:null,invalid:false,contract_revision:null,implementation_snapshot:null,sha256:null,acknowledged_by:null} */
    private function missing(): array
    {
        return [
            'exists' => false,
            'status' => null,
            'report_status' => null,
            'invalid' => false,
            'contract_revision' => null,
            'implementation_snapshot' => null,
            'sha256' => null,
            'acknowledged_by' => null,
        ];
    }

    /** @return array{exists:true,status:null,report_status:null,invalid:true,contract_revision:null,implementation_snapshot:null,sha256:null,acknowledged_by:null} */
    private function invalid(): array
    {
        return [
            'exists' => true,
            'status' => null,
            'report_status' => null,
            'invalid' => true,
            'contract_revision' => null,
            'implementation_snapshot' => null,
            'sha256' => null,
            'acknowledged_by' => null,
        ];
    }
}
