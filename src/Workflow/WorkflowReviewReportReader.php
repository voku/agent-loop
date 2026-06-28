<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

final readonly class WorkflowReviewReportReader
{
    public function __construct(private string $rootPath)
    {
    }

    public function relativePath(string $taskId): string
    {
        return '.agent-recall/reviews/' . $taskId . '.blindspots.json';
    }

    public function absolutePath(string $taskId): string
    {
        return rtrim($this->rootPath, '/') . '/' . $this->relativePath($taskId);
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
