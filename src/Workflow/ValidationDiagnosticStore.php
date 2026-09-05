<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;

/**
 * Storage and attempt tracking for task validation diagnostics.
 */
final readonly class ValidationDiagnosticStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function record(ValidationDiagnostic $diagnostic): void
    {
        $dir = $this->ensureDirectory();
        $path = $dir . '/' . $diagnostic->taskId . '.json';
        $json = json_encode($diagnostic->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        file_put_contents($path, $json . "\n");
    }

    public function find(string $taskId): ?ValidationDiagnostic
    {
        return $this->latest($taskId);
    }

    public function canAttemptRepair(string $taskId, ?int $contractRevision = null): bool
    {
        $revision = $contractRevision;
        if ($revision === null) {
            $diagnostic = $this->latest($taskId);
            if ($diagnostic !== null) {
                $revision = $diagnostic->contractRevision;
            } else {
                $data = $this->loadRepairData($taskId);
                $revision = isset($data['revision']) ? (int) $data['revision'] : 0;
            }
        }

        return $this->repairAttemptCount($taskId, $revision) < WorkflowRepairCommand::DEFAULT_MAX_ATTEMPTS;
    }

    public function latest(string $taskId): ?ValidationDiagnostic
    {
        $path = $this->ensureDirectory() . '/' . $taskId . '.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        return ValidationDiagnostic::fromArray($decoded);
    }

    public function repairAttemptCount(string $taskId, int $contractRevision): int
    {
        $data = $this->loadRepairData($taskId);
        if (($data['revision'] ?? null) !== $contractRevision) {
            return 0;
        }

        return (int) ($data['attempts'] ?? 0);
    }

    public function incrementRepairAttempt(string $taskId, int $contractRevision): int
    {
        $data = $this->loadRepairData($taskId);
        $currentRevision = (int) ($data['revision'] ?? 0);
        $attempts = ($currentRevision === $contractRevision) ? (int) ($data['attempts'] ?? 0) : 0;
        $newAttempts = $attempts + 1;

        $dir = $this->ensureDirectory();
        $path = $dir . '/' . $taskId . '.repairs.json';
        $payload = [
            'task_id' => $taskId,
            'revision' => $contractRevision,
            'attempts' => $newAttempts,
            'updated_at' => gmdate('c'),
        ];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");

        return $newAttempts;
    }

    public function clear(string $taskId): void
    {
        $dir = (new ProjectLayout($this->rootPath))->diagnosticsRoot();
        $diagPath = $dir . '/' . $taskId . '.json';
        $repairsPath = $dir . '/' . $taskId . '.repairs.json';
        if (is_file($diagPath)) {
            unlink($diagPath);
        }
        if (is_file($repairsPath)) {
            unlink($repairsPath);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadRepairData(string $taskId): array
    {
        $path = (new ProjectLayout($this->rootPath))->diagnosticsRoot() . '/' . $taskId . '.repairs.json';
        if (!is_file($path)) {
            return [];
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            return [];
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : [];
        } catch (JsonException) {
            return [];
        }
    }

    private function ensureDirectory(): string
    {
        $dir = (new ProjectLayout($this->rootPath))->diagnosticsRoot();
        if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create diagnostics directory: ' . $dir);
        }

        return $dir;
    }
}
