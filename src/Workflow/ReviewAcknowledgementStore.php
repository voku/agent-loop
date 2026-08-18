<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRun;

final readonly class ReviewAcknowledgementStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function record(
        GovernedRun $run,
        TaskContract $contract,
        ImplementationSnapshot $implementation,
        string $reportSha256,
        string $acknowledgedBy,
    ): ReviewAcknowledgement {
        $acknowledgedBy = trim($acknowledgedBy);
        if ($acknowledgedBy === '') {
            throw new RuntimeException('Review acknowledgement requires a named actor.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $reportSha256) !== 1) {
            throw new RuntimeException('Review acknowledgement report identity must be a sha256 digest.');
        }
        if (
            $run->taskId !== $contract->taskId
            || $run->taskId !== $implementation->taskId
            || $run->contractRevision !== $contract->revision
            || $implementation->contractRevision !== $contract->revision
        ) {
            throw new RuntimeException('Review acknowledgement does not match the current Run / Contract / implementation boundary.');
        }

        $path = $this->path($run->taskId);
        $existing = $this->find($run->taskId);
        if ($existing !== null) {
            $sameBoundary = $existing->runId === $run->runId
                && $existing->contractRevision === $contract->revision
                && hash_equals($existing->implementationSnapshot, $implementation->digest)
                && hash_equals($existing->reportSha256, $reportSha256);
            if ($sameBoundary) {
                if ($existing->acknowledgedBy !== $acknowledgedBy) {
                    throw new RuntimeException(
                        'The current review report is already acknowledged by ' . $existing->acknowledgedBy . '.',
                    );
                }

                return $existing;
            }

            $this->archive($existing);
        }

        $acknowledgement = new ReviewAcknowledgement(
            runId: $run->runId,
            taskId: $run->taskId,
            contractRevision: $contract->revision,
            implementationSnapshot: $implementation->digest,
            reportSha256: $reportSha256,
            acknowledgedBy: $acknowledgedBy,
            acknowledgedAt: (new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM),
            path: $path,
        );
        $this->write($acknowledgement);

        return $acknowledgement;
    }

    public function find(string $taskId): ?ReviewAcknowledgement
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read review acknowledgement: ' . $path);
        }

        return $this->decode($contents, $path, $taskId);
    }

    public function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->runRoot($taskId) . '/review-acknowledgement.json';
    }

    private function write(ReviewAcknowledgement $acknowledgement): void
    {
        $directory = dirname($acknowledgement->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review acknowledgement directory: ' . $directory);
        }
        $this->atomicWrite($acknowledgement->path, CanonicalJson::pretty($acknowledgement->toArray()));
    }

    private function archive(ReviewAcknowledgement $acknowledgement): void
    {
        $json = CanonicalJson::pretty($acknowledgement->toArray());
        $directory = dirname($acknowledgement->path) . '/review-acknowledgements/archive';
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create review acknowledgement archive directory: ' . $directory);
        }
        $path = $directory . '/' . hash('sha256', $json) . '.json';
        if (!is_file($path)) {
            $this->atomicWrite($path, $json);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $contents) === false) {
            throw new RuntimeException('Unable to write temporary review acknowledgement: ' . $temporary);
        }
        if (!rename($temporary, $path)) {
            if (is_file($temporary) && !unlink($temporary)) {
                throw new RuntimeException('Unable to remove failed review acknowledgement temporary file: ' . $temporary);
            }
            throw new RuntimeException('Unable to publish review acknowledgement: ' . $path);
        }
    }

    private function decode(string $json, string $path, string $expectedTaskId): ReviewAcknowledgement
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid review acknowledgement JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (
            !is_array($data)
            || ($data['schema_version'] ?? null) !== '1.0'
            || ($data['kind'] ?? null) !== 'review_acknowledgement'
        ) {
            throw new RuntimeException('Unsupported review acknowledgement schema in ' . $path . '.');
        }

        $taskId = $this->requiredString($data, 'task_id', $path);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException('Review acknowledgement belongs to another task: ' . $path);
        }
        $revision = $data['contract_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Review acknowledgement contract_revision must be positive in ' . $path . '.');
        }
        $snapshot = $this->digest($data['implementation_snapshot'] ?? null, 'implementation_snapshot', $path);
        $reportSha256 = $this->digest($data['report_sha256'] ?? null, 'report_sha256', $path);

        return new ReviewAcknowledgement(
            runId: $this->requiredString($data, 'run_id', $path),
            taskId: $taskId,
            contractRevision: $revision,
            implementationSnapshot: $snapshot,
            reportSha256: $reportSha256,
            acknowledgedBy: $this->requiredString($data, 'acknowledged_by', $path),
            acknowledgedAt: $this->requiredString($data, 'acknowledged_at', $path),
            path: $path,
        );
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($path . ' requires non-empty ' . $key . '.');
        }

        return trim($value);
    }

    private function digest(mixed $value, string $name, string $path): string
    {
        if (!is_string($value) || preg_match('/^sha256:[a-f0-9]{64}$/', $value) !== 1) {
            throw new RuntimeException($path . ' requires ' . $name . ' to be a sha256 digest.');
        }

        return $value;
    }
}
