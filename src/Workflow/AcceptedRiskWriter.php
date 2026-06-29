<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use RuntimeException;

final readonly class AcceptedRiskWriter
{
    public function __construct(private string $rootPath)
    {
    }

    public function relativePath(string $taskId): string
    {
        return '.agent-loop/risks/' . $taskId . '.accepted-risk.md';
    }

    public function write(string $taskId, string $reason): string
    {
        $dir = rtrim($this->rootPath, '/') . '/.agent-loop/risks';
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create accepted-risk directory: ' . $dir);
        }

        $relative = $this->relativePath($taskId);
        $content = "# Accepted risk for {$taskId}\n\n";
        $content .= "Reason: {$reason}\n\n";
        $content .= "Bypassing workflow close gates does not approve code or durable learning.\n";
        $content .= "Human review remains required.\n";
        $path = rtrim($this->rootPath, '/') . '/' . $relative;
        if (is_dir($path)) {
            throw new RuntimeException('Could not write accepted-risk file: ' . $relative);
        }

        $written = file_put_contents($path, $content);
        if ($written === false) {
            throw new RuntimeException('Could not write accepted-risk file: ' . $relative);
        }

        return $relative;
    }
}
