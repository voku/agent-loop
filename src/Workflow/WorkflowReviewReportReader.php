<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use Throwable;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentRecallCompiler\Review\ReviewReportReader;

/**
 * Workflow-shaped projection over agent-recall-compiler's typed report reader.
 *
 * The owner package validates report version, task identity, findings and stored
 * status. agent-loop only adds repository-relative addressing for its manifest.
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
     * @return array{
     *   exists: bool,
     *   status: string|null,
     *   invalid: bool,
     *   contract_revision: int|null,
     *   implementation_snapshot: string|null,
     *   sha256: string|null
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

        return [
            'exists' => true,
            'status' => $artifact->report->status(),
            'invalid' => false,
            'contract_revision' => $artifact->report->contractRevision,
            'implementation_snapshot' => $artifact->report->implementationSnapshot,
            'sha256' => $artifact->sha256,
        ];
    }

    /** @return array{exists:false,status:null,invalid:false,contract_revision:null,implementation_snapshot:null,sha256:null} */
    private function missing(): array
    {
        return [
            'exists' => false,
            'status' => null,
            'invalid' => false,
            'contract_revision' => null,
            'implementation_snapshot' => null,
            'sha256' => null,
        ];
    }

    /** @return array{exists:true,status:null,invalid:true,contract_revision:null,implementation_snapshot:null,sha256:null} */
    private function invalid(): array
    {
        return [
            'exists' => true,
            'status' => null,
            'invalid' => true,
            'contract_revision' => null,
            'implementation_snapshot' => null,
            'sha256' => null,
        ];
    }
}
