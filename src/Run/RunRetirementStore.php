<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;

/**
 * Durable record that a governed Run was deliberately given up instead of completed.
 *
 * A Run that was overtaken by a product decision, or whose work landed outside the governed
 * lifecycle, can never pass its close gates: they would demand evidence for an implementation that
 * no longer exists. Without an honest terminal state the only exits are an accepted-risk "done"
 * (a false statement) or leaving the Run open forever (noise that hides live work). A retirement
 * says what happened, who decided it and why; it is bound to one Run id, so a later Run for the
 * same task is not retired by it.
 */
final class RunRetirementStore
{
    public const STATUSES = ['superseded', 'abandoned'];

    public function __construct(private readonly string $rootPath)
    {
    }

    public function record(GovernedRun $run, string $status, string $reason, string $by): RunRetirement
    {
        if (!in_array($status, self::STATUSES, true)) {
            throw new RuntimeException('Retirement status must be superseded or abandoned.');
        }
        if (trim($reason) === '' || trim($by) === '') {
            throw new RuntimeException('A retirement needs a reason and the actor who decided it.');
        }
        $existing = $this->find($run->taskId);
        if ($existing !== null && $existing->runId === $run->runId) {
            throw new RuntimeException('Governed Run is already retired as ' . $existing->status . '.');
        }
        if ((new RunVerificationReceiptStore($this->rootPath))->find($run->taskId)?->runId === $run->runId) {
            throw new RuntimeException('Governed Run already has final verification evidence; close it instead of retiring it.');
        }

        $retirement = new RunRetirement(
            $run->taskId,
            $run->runId,
            $run->contractRevision,
            $status,
            trim($reason),
            trim($by),
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
        $path = $this->path($run->taskId);
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($retirement->toArray())) === false || !rename($tmp, $path)) {
            if (is_file($tmp) && !unlink($tmp)) {
                throw new RuntimeException('Unable to remove temporary Run retirement artifact: ' . $tmp);
            }
            throw new RuntimeException('Unable to write Run retirement artifact: ' . $path);
        }

        return $retirement;
    }

    /** The retirement of exactly this Run; one recorded for an earlier Run of the task does not apply. */
    public function findForRun(GovernedRun $run): ?RunRetirement
    {
        $retirement = $this->find($run->taskId);

        return $retirement !== null && $retirement->runId === $run->runId ? $retirement : null;
    }

    public function find(string $taskId): ?RunRetirement
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read Run retirement artifact: ' . $path);
        }
        try {
            $data = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Run retirement artifact is not valid JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data)
            || ($data['kind'] ?? null) !== 'governed_run_retirement'
            || ($data['task_id'] ?? null) !== $taskId
            || !is_string($data['run_id'] ?? null)
            || !is_int($data['contract_revision'] ?? null)
            || !in_array($data['status'] ?? null, self::STATUSES, true)
            || !is_string($data['reason'] ?? null)
            || !is_string($data['retired_by'] ?? null)
            || !is_string($data['retired_at'] ?? null)
        ) {
            throw new RuntimeException('Run retirement artifact is malformed: ' . $path);
        }

        return new RunRetirement(
            $taskId,
            $data['run_id'],
            $data['contract_revision'],
            $data['status'],
            $data['reason'],
            $data['retired_by'],
            $data['retired_at'],
        );
    }

    private function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->runChildFile($taskId, 'retirement.json');
    }
}
