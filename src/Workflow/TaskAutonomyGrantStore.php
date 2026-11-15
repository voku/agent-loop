<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;

/** Persists the current local, task-scoped autonomy request owned by agent-loop. */
final readonly class TaskAutonomyGrantStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function issue(string $taskId, string $by, DateTimeImmutable $now, DateTimeImmutable $until): TaskAutonomyGrant
    {
        $by = WorkflowActorAttribution::normalize($by, 'issue task autonomy');

        return $this->withLock($taskId, function () use ($taskId, $by, $now, $until): TaskAutonomyGrant {
            $existing = $this->find($taskId);
            if ($existing !== null && $existing->revokedAt === null && $now < $existing->expiresAt) {
                throw new RuntimeException('An unexpired task autonomy grant already exists; revoke it before issuing a replacement.');
            }
            $contract = (new TaskContractStore($this->rootPath))->load($taskId);
            $grant = TaskAutonomyGrant::request($contract, $by, $now, $until);
            if ($existing !== null) {
                $this->archiveCurrent($taskId);
            }
            $this->write($grant);

            return $grant;
        });
    }

    public function find(string $taskId): ?TaskAutonomyGrant
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read task autonomy grant: ' . $path);
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid task autonomy grant JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'task_autonomy_grant') {
            throw new RuntimeException('Unsupported task autonomy grant schema in ' . $path . '.');
        }
        $grant = $data['grant'] ?? null;
        if (!is_array($grant)
            || ($grant['task_id'] ?? null) !== $taskId
            || !is_int($grant['contract_revision'] ?? null)
            || !is_string($grant['contract_digest'] ?? null)
            || !is_string($grant['requested_by'] ?? null)
            || !is_string($grant['issued_at'] ?? null)
            || !is_string($grant['expires_at'] ?? null)
            || (isset($grant['revoked_at']) && !is_string($grant['revoked_at']))
            || (isset($grant['revoked_by']) && !is_string($grant['revoked_by']))) {
            throw new RuntimeException('Malformed task autonomy grant in ' . $path . '.');
        }

        try {
            return new TaskAutonomyGrant(
                $taskId,
                $grant['contract_revision'],
                $grant['contract_digest'],
                $grant['requested_by'],
                new DateTimeImmutable($grant['issued_at']),
                new DateTimeImmutable($grant['expires_at']),
                isset($grant['revoked_at']) ? new DateTimeImmutable($grant['revoked_at']) : null,
                isset($grant['revoked_by']) ? $grant['revoked_by'] : null,
            );
        } catch (\Throwable $exception) {
            throw new RuntimeException('Invalid task autonomy grant in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
    }

    /** @param list<string> $targetPaths */
    public function currentEligibility(string $taskId, string $operation, DateTimeImmutable $now, array $targetPaths = []): bool
    {
        $grant = $this->find($taskId);
        if ($grant === null) {
            return false;
        }

        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        if ($contract === null) {
            return false;
        }

        return $grant->isEligibleFor($contract, $operation, $now, $targetPaths);
    }

    public function revoke(string $taskId, string $by, DateTimeImmutable $now): TaskAutonomyGrant
    {
        $by = WorkflowActorAttribution::normalize($by, 'revoke task autonomy');

        return $this->withLock($taskId, function () use ($taskId, $by, $now): TaskAutonomyGrant {
            $grant = $this->find($taskId);
            if ($grant === null) {
                throw new RuntimeException('No task autonomy grant exists for ' . $taskId . '.');
            }
            $revoked = $grant->revoke($now, $by);
            $this->write($revoked);

            return $revoked;
        });
    }

    public function path(string $taskId): string
    {
        new WorkflowTaskId($taskId);

        return (new ProjectLayout($this->rootPath))->runFile($taskId, 'task-autonomy-grant.json');
    }

    private function write(TaskAutonomyGrant $grant): void
    {
        $path = $this->path($grant->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create task autonomy directory: ' . $directory);
        }
        $payload = [
            'schema_version' => '1.0',
            'kind' => 'task_autonomy_grant',
            'grant' => [
                'task_id' => $grant->taskId,
                'contract_revision' => $grant->contractRevision,
                'contract_digest' => $grant->contractDigest,
                'requested_by' => $grant->requestedBy,
                'issued_at' => $grant->issuedAt->format(DateTimeInterface::ATOM),
                'expires_at' => $grant->expiresAt->format(DateTimeInterface::ATOM),
                'revoked_at' => $grant->revokedAt?->format(DateTimeInterface::ATOM),
                'revoked_by' => $grant->revokedBy,
            ],
        ];
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, CanonicalJson::pretty($payload)) === false || !chmod($temporary, 0o600) || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Unable to atomically publish task autonomy grant: ' . $path);
        }
    }

    private function archiveCurrent(string $taskId): void
    {
        $currentPath = $this->path($taskId);
        $contents = file_get_contents($currentPath);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to preserve previous task autonomy grant: ' . $currentPath);
        }
        $layout = new ProjectLayout($this->rootPath);
        $archivePath = $layout->runChildFile(
            $taskId,
            'task-autonomy-grants/archive/' . hash('sha256', $contents) . '.json',
        );
        if (is_file($archivePath)) {
            return;
        }
        $directory = dirname($archivePath);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create task autonomy archive directory: ' . $directory);
        }
        $temporary = $archivePath . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents) === false || !chmod($temporary, 0o600) || !rename($temporary, $archivePath)) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            throw new RuntimeException('Unable to preserve previous task autonomy grant: ' . $archivePath);
        }
    }

    private function withLock(string $taskId, callable $operation): mixed
    {
        $path = $this->path($taskId) . '.lock';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create task autonomy lock directory: ' . $directory);
        }
        $handle = fopen($path, 'c');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new RuntimeException('Unable to lock task autonomy grant for ' . $taskId . '.');
        }
        try {
            return $operation();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
