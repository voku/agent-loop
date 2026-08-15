<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\RecallOutputRoot;

final readonly class WorkflowReviewReportReader
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * `agent-recall-compiler review` writes its report as a `reviews/`
     * subfolder of the same `--output-dir` it read its compiled recall
     * inputs from; `Dispatcher::resolveReviewArgv()` defaults that
     * `--output-dir` to `<recall-root>/<task-id>`, so the report lands at
     * `<recall-root>/<task-id>/reviews/<task-id>.blindspots.json`.
     */
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
        $path = $this->absolutePath($taskId);
        if (!is_file($path)) {
            return $this->missing();
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return $this->invalid();
        }

        $data = json_decode($contents, true);
        if (!is_array($data) || !isset($data['status']) || !is_string($data['status'])) {
            return $this->invalid();
        }

        $status = strtolower($data['status']);
        if (!in_array($status, ['ok', 'warn', 'fail'], true)) {
            return $this->invalid();
        }
        $revision = $data['contract_revision'] ?? null;
        $snapshot = $data['implementation_snapshot'] ?? null;
        if (($revision === null) !== ($snapshot === null)) {
            return $this->invalid();
        }
        if ($revision !== null && (!is_int($revision) || $revision < 1)) {
            return $this->invalid();
        }
        if ($snapshot !== null && (!is_string($snapshot) || preg_match('/^sha256:[a-f0-9]{64}$/', $snapshot) !== 1)) {
            return $this->invalid();
        }

        return [
            'exists' => true,
            'status' => $status,
            'invalid' => false,
            'contract_revision' => $revision,
            'implementation_snapshot' => $snapshot,
            'sha256' => 'sha256:' . hash('sha256', $contents),
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
