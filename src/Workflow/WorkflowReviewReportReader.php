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
     * @return array{exists: bool, status: string|null, invalid: bool}
     */
    public function read(string $taskId): array
    {
        $path = $this->absolutePath($taskId);
        if (!is_file($path)) {
            return ['exists' => false, 'status' => null, 'invalid' => false];
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data) || !isset($data['status']) || !is_string($data['status'])) {
            return ['exists' => true, 'status' => null, 'invalid' => true];
        }

        $status = strtolower($data['status']);
        if (!in_array($status, ['ok', 'warn', 'fail'], true)) {
            return ['exists' => true, 'status' => null, 'invalid' => true];
        }

        return ['exists' => true, 'status' => $status, 'invalid' => false];
    }
}
