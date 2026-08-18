<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentSession\Session;

final class RunVerificationReceiptStore
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @param list<array{command: string, status: string, exit_code: int|null, executed_at: string|null, recorded_by: string|null, duration_ms: int|null}> $obligations
     */
    public function record(
        GovernedRun $run,
        TaskContract $contract,
        Session $session,
        string $implementationSnapshot,
        string $verdict,
        array $obligations,
    ): RunVerificationReceipt {
        if (!in_array($verdict, ['satisfied', 'unsatisfied', 'accepted_risk'], true)) {
            throw new RuntimeException('Verification receipt verdict must be satisfied, unsatisfied, or accepted_risk.');
        }
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $implementationSnapshot) !== 1) {
            throw new RuntimeException('Verification receipt implementation snapshot must be a sha256 digest.');
        }
        if ($contract->taskId !== $run->taskId || $session->taskId !== $run->taskId) {
            throw new RuntimeException('Verification receipt task lineage does not match governed Run.');
        }
        if ($contract->revision !== $run->contractRevision) {
            throw new RuntimeException('Verification receipt Contract revision does not match governed Run.');
        }
        $contractHash = hash_file('sha256', $contract->path);
        if ($contractHash === false || !hash_equals($run->contractSource['sha256'], 'sha256:' . $contractHash)) {
            throw new RuntimeException('Verification receipt Contract digest does not match governed Run.');
        }
        if ($contract->validation === []) {
            throw new RuntimeException('Governed Contract has no verification obligations.');
        }
        $commands = array_column($obligations, 'command');
        if ($commands !== $contract->validation) {
            throw new RuntimeException('Verification receipt obligations do not exactly match Contract validation order.');
        }

        $existing = $this->find($run->taskId);
        if ($existing !== null) {
            if (
                $existing->runId !== $run->runId
                || $existing->contractRevision !== $contract->revision
                || $existing->contractSha256 !== $run->contractSource['sha256']
                || $existing->implementationSnapshot !== $implementationSnapshot
                || $existing->verdict !== $verdict
                || $existing->obligations !== $obligations
                || $existing->sourceSessionId !== $session->id
            ) {
                throw new RuntimeException('Governed Run already has a different final verification receipt.');
            }

            return $existing;
        }

        $receipt = new RunVerificationReceipt(
            $run->runId,
            $run->taskId,
            $contract->revision,
            $run->contractSource['sha256'],
            $verdict,
            $obligations,
            $session->id,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            $this->path($run->taskId),
            $implementationSnapshot,
        );
        $this->write($receipt);

        return $receipt;
    }

    public function find(string $taskId): ?RunVerificationReceipt
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read run verification receipt: ' . $path);
        }

        return $this->decode($contents, $path, $taskId);
    }

    public function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->runRoot($taskId) . '/verification.json';
    }

    private function write(RunVerificationReceipt $receipt): void
    {
        $directory = dirname($receipt->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create run verification directory: ' . $directory);
        }
        $tmp = $receipt->path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($receipt->toArray())) === false || !rename($tmp, $receipt->path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write run verification receipt: ' . $receipt->path);
        }
    }

    private function decode(string $json, string $path, string $expectedTaskId): RunVerificationReceipt
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid run verification receipt JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        $schema = is_array($data) ? ($data['schema_version'] ?? null) : null;
        if (!is_array($data) || !in_array($schema, ['1.0', '1.1'], true) || ($data['kind'] ?? null) !== 'run_verification_receipt') {
            throw new RuntimeException('Unsupported run verification receipt schema in ' . $path . '.');
        }
        $taskId = PersistedJson::requiredString($data, 'task_id', $path);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException('Verification receipt task id does not match requested task: ' . $path);
        }
        $revision = $data['contract_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Verification receipt contract_revision must be positive in ' . $path . '.');
        }
        $sha = PersistedJson::requiredString($data, 'contract_sha256', $path);
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $sha) !== 1) {
            throw new RuntimeException('Verification receipt contract_sha256 is invalid in ' . $path . '.');
        }
        $implementationSnapshot = null;
        if ($schema === '1.1') {
            $implementationSnapshot = PersistedJson::requiredString($data, 'implementation_snapshot', $path);
            if (preg_match('/^sha256:[a-f0-9]{64}$/', $implementationSnapshot) !== 1) {
                throw new RuntimeException('Verification receipt implementation_snapshot is invalid in ' . $path . '.');
            }
        }
        $verdict = PersistedJson::requiredString($data, 'verdict', $path);
        if (!in_array($verdict, ['satisfied', 'unsatisfied', 'accepted_risk'], true)) {
            throw new RuntimeException('Verification receipt verdict is invalid in ' . $path . '.');
        }
        $obligations = $data['obligations'] ?? null;
        if (!is_array($obligations)) {
            throw new RuntimeException('Verification receipt obligations must be an array in ' . $path . '.');
        }
        $normalized = [];
        foreach ($obligations as $obligation) {
            if (!is_array($obligation)) {
                throw new RuntimeException('Verification receipt obligation must be an object in ' . $path . '.');
            }
            $command = PersistedJson::requiredString($obligation, 'command', $path . '#obligation');
            $status = PersistedJson::requiredString($obligation, 'status', $path . '#obligation');
            if (!in_array($status, ['passed', 'failed', 'missing'], true)) {
                throw new RuntimeException('Verification receipt obligation status is invalid in ' . $path . '.');
            }
            $exitCode = $obligation['exit_code'] ?? null;
            if ($exitCode !== null && (!is_int($exitCode) || $exitCode < 0)) {
                throw new RuntimeException('Verification receipt obligation exit_code is invalid in ' . $path . '.');
            }
            $duration = $obligation['duration_ms'] ?? null;
            if ($duration !== null && (!is_int($duration) || $duration < 0)) {
                throw new RuntimeException('Verification receipt obligation duration_ms is invalid in ' . $path . '.');
            }
            $normalized[] = [
                'command' => $command,
                'status' => $status,
                'exit_code' => $exitCode,
                'executed_at' => $this->nullableString($obligation['executed_at'] ?? null, $path . '#obligation.executed_at'),
                'recorded_by' => $this->nullableString($obligation['recorded_by'] ?? null, $path . '#obligation.recorded_by'),
                'duration_ms' => $duration,
            ];
        }

        return new RunVerificationReceipt(
            PersistedJson::requiredString($data, 'run_id', $path),
            $taskId,
            $revision,
            $sha,
            $verdict,
            $normalized,
            PersistedJson::requiredString($data, 'source_session_id', $path),
            PersistedJson::requiredString($data, 'verified_at', $path),
            $path,
            $implementationSnapshot,
        );
    }

    private function nullableString(mixed $value, string $path): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($path . ' must be null or non-empty string.');
        }

        return trim($value);
    }
}
