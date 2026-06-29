<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use Throwable;
use voku\AgentSession\SessionStore;

final readonly class WorkflowStatusCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = new WorkflowTaskId($args[0] ?? '');
            $this->printSession($taskId->value);
            $this->printRecall($taskId->value);
            $this->printReview($taskId->value);
            return 0;
        } catch (InvalidArgumentException $e) {
            fwrite(\STDERR, '[FAIL] workflow status: ' . $e->getMessage() . "\n");
            return 1;
        } catch (Throwable $e) {
            fwrite(\STDERR, '[FAIL] workflow status: ' . $e->getMessage() . "\n");
            return 1;
        }
    }

    private function printSession(string $taskId): void
    {
        $sessionsRoot = rtrim($this->rootPath, '/') . '/session_plan';
        if (!is_dir($sessionsRoot)) {
            echo "[WARN] session: no session found for task {$taskId}\n";
            return;
        }

        $sessions = array_values(array_filter((new SessionStore())->all($sessionsRoot), static fn ($session): bool => $session->taskId === $taskId));
        if ($sessions === []) {
            echo "[WARN] session: no session found for task {$taskId}\n";
            return;
        }
        $active = count(array_filter($sessions, static fn ($session): bool => !$session->status->isClosed()));
        echo '[OK] session: ' . count($sessions) . " session(s) found for task {$taskId}, {$active} active\n";
    }

    private function printRecall(string $taskId): void
    {
        $relative = 'recall/' . $taskId . '/meta.json';
        echo (is_file(rtrim($this->rootPath, '/') . '/' . $relative) ? '[OK] recall: found ' : '[PENDING] recall: missing ') . $relative . "\n";
    }

    private function printReview(string $taskId): void
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $report = $reader->read($taskId);
        $relative = $reader->relativePath($taskId);
        if (!$report['exists']) {
            echo "[PENDING] review: missing {$relative}\n";
            return;
        }
        if ($report['invalid']) {
            echo "[WARN] review: report JSON is invalid\n";
            return;
        }
        $prefix = $report['status'] === 'ok' ? '[OK]' : '[WARN]';
        echo "{$prefix} review: found {$relative} with status {$report['status']}\n";
    }
}
