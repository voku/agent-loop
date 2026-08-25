<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\Transparency\ReviewCurrency;
use voku\AgentLoop\Workflow\Transparency\ReviewDetail;
use voku\AgentLoop\Workflow\Transparency\ReviewFinding;
use voku\AgentRecallCompiler\Review\BlindSpotFinding;
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
        $detail = $this->detail($taskId);

        return [
            'exists' => $detail->exists,
            'status' => $detail->lifecycleStatus,
            'report_status' => $detail->reportStatus,
            'invalid' => $detail->invalid,
            'contract_revision' => $detail->contractRevision,
            'implementation_snapshot' => $detail->implementationSnapshot,
            'sha256' => $detail->sha256,
            'acknowledged_by' => $detail->acknowledgedBy,
        ];
    }

    /**
     * The same lifecycle answer as `read()`, plus the exact report's findings
     * and acknowledgement timestamp.
     *
     * Findings travel with their currency so a stale report can be displayed as
     * stale evidence without ever looking current.
     */
    public function detail(string $taskId): ReviewDetail
    {
        $path = $this->relativePath($taskId);
        $outputDirectory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        try {
            $artifact = (new ReviewReportReader($this->rootPath))->read($taskId, $outputDirectory);
        } catch (Throwable) {
            return ReviewDetail::invalid($path);
        }
        if ($artifact === null) {
            return ReviewDetail::missing($path);
        }

        $reportStatus = $artifact->report->status();
        $findings = array_map(
            static fn (BlindSpotFinding $finding): ReviewFinding => ReviewFinding::fromOwner($finding),
            $artifact->report->findings,
        );

        try {
            $binding = $this->binding(
                $taskId,
                $reportStatus,
                $artifact->sha256,
                $artifact->report->contractRevision,
                $artifact->report->implementationSnapshot,
            );
        } catch (Throwable) {
            return ReviewDetail::invalid($path);
        }

        return ReviewDetail::present(
            currency: $binding['currency'],
            lifecycleStatus: $binding['status'] ?? $reportStatus,
            reportStatus: $reportStatus,
            contractRevision: $artifact->report->contractRevision,
            implementationSnapshot: $artifact->report->implementationSnapshot,
            sha256: $artifact->sha256,
            acknowledgedBy: $binding['acknowledged_by'],
            acknowledgedAt: $binding['acknowledged_at'],
            findings: $findings,
            path: $path,
        );
    }

    /**
     * @return array{
     *     currency: ReviewCurrency,
     *     status: string|null,
     *     acknowledged_by: string|null,
     *     acknowledged_at: string|null
     * }
     */
    private function binding(
        string $taskId,
        string $reportStatus,
        string $reportSha256,
        ?int $reportContractRevision,
        ?string $reportImplementationSnapshot,
    ): array {
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        if (
            $contract === null
            || $run === null
            || $contract->status !== TaskContract::APPROVED
            || $run->contractRevision !== $contract->revision
        ) {
            return [
                'currency' => ReviewCurrency::UNBOUND,
                'status' => null,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
            ];
        }

        $implementation = ImplementationSnapshot::capture($this->rootPath, $contract);
        $reportCurrent = $reportContractRevision === $contract->revision
            && $reportImplementationSnapshot !== null
            && hash_equals($reportImplementationSnapshot, $implementation->digest);
        if (!$reportCurrent) {
            return [
                'currency' => ReviewCurrency::STALE,
                'status' => 'stale',
                'acknowledged_by' => null,
                'acknowledged_at' => null,
            ];
        }

        // A `fail` report is already a lifecycle answer. Acknowledgement is the
        // gate for reports that would otherwise read as passable, so asking for
        // one here would let an acknowledged failure look resolved.
        if (!in_array($reportStatus, ['ok', 'warn'], true)) {
            return [
                'currency' => ReviewCurrency::CURRENT,
                'status' => null,
                'acknowledged_by' => null,
                'acknowledged_at' => null,
            ];
        }

        // Acknowledgement authority is the exact identity, not the fact that
        // some acknowledgement exists: a different Run, Contract revision,
        // implementation or report SHA-256 is somebody approving other work.
        $acknowledgement = (new ReviewAcknowledgementStore($this->rootPath))->find($taskId);
        if (
            $acknowledgement === null
            || $acknowledgement->runId !== $run->runId
            || $acknowledgement->contractRevision !== $contract->revision
            || !hash_equals($acknowledgement->implementationSnapshot, $implementation->digest)
            || !hash_equals($acknowledgement->reportSha256, $reportSha256)
        ) {
            return [
                'currency' => ReviewCurrency::CURRENT,
                'status' => 'unacknowledged',
                'acknowledged_by' => null,
                'acknowledged_at' => null,
            ];
        }

        return [
            'currency' => ReviewCurrency::CURRENT,
            'status' => null,
            'acknowledged_by' => $acknowledgement->acknowledgedBy,
            'acknowledged_at' => $acknowledgement->acknowledgedAt,
        ];
    }
}
