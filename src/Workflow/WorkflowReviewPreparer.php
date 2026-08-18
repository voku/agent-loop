<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentRecallCompiler\Review\BlindSpotReviewer;
use voku\AgentRecallCompiler\Review\ReviewReport;
use voku\AgentRecallCompiler\Review\ReviewReportWriter;

/**
 * Reconciles the deterministic blind-spot report for the current implementation.
 * Review acknowledgement remains a separate authority-bearing decision.
 */
final readonly class WorkflowReviewPreparer
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array{exists: bool,status: string|null,invalid: bool,contract_revision: int|null,implementation_snapshot: string|null,sha256: string|null}
     */
    public function prepare(TaskContract $contract): array
    {
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('Finish-owned review preparation requires an approved Contract.');
        }

        $snapshot = ImplementationSnapshot::capture($this->rootPath, $contract);
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $current = $reader->read($contract->taskId);
        if (
            $current['exists']
            && !$current['invalid']
            && $current['contract_revision'] === $contract->revision
            && $current['implementation_snapshot'] === $snapshot->digest
        ) {
            return $current;
        }

        $outputDirectory = RecallOutputRoot::resolve($this->rootPath) . '/' . $contract->taskId;
        $report = (new BlindSpotReviewer($this->rootPath))->review($contract->taskId, $outputDirectory);
        $bound = new ReviewReport(
            taskId: $report->taskId,
            findings: $report->findings,
            contractRevision: $contract->revision,
            implementationSnapshot: $snapshot->digest,
        );
        (new ReviewReportWriter($this->rootPath))->write($bound, $outputDirectory);

        $prepared = $reader->read($contract->taskId);
        if (!$prepared['exists'] || $prepared['invalid'] || $prepared['sha256'] === null) {
            throw new RuntimeException('Blind-spot review preparation completed without a readable exact report identity.');
        }

        return $prepared;
    }
}
