<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;

/** @internal Owner-side evidence persistence; external hosts submit references, not records. */
final readonly class ExecutionEvidenceStore
{
    public function __construct(private string $rootPath)
    {
    }

    /** @return non-empty-string */
    public function referenceFor(ExecutionEvidenceClaim $claim): string
    {
        return 'execution-evidence:sha256:' . hash('sha256', CanonicalJson::pretty($claim->toArray()));
    }

    /** @return non-empty-string */
    public function record(ExecutionEvidenceClaim $claim): string
    {
        $reference = $this->referenceFor($claim);
        $record = new ExecutionEvidenceRecord(
            $reference,
            $claim,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        );
        $path = $this->path($claim->taskId, $reference);
        if (is_file($path)) {
            $existing = $this->load($claim->taskId, $reference);
            if ($existing->claim->toArray() !== $claim->toArray()) {
                throw new RuntimeException('EVIDENCE_MISMATCH: owner evidence reference already exists with different content.');
            }

            return $reference;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create execution evidence directory: ' . $directory);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($record->toArray())) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('Unable to persist execution evidence: ' . $path);
        }

        return $reference;
    }

    public function assertCurrent(
        string $reference,
        ExecutionEvidenceKind $kind,
        ExecutionPlan $plan,
        string $stageId,
        int $attempt,
        string $candidateRevision,
    ): void {
        $record = $this->load($plan->taskId, $reference);
        $claim = $record->claim;
        if ($claim->taskId !== $plan->taskId
            || $claim->runId !== $plan->runId
            || $claim->contractRevision !== $plan->contractRevision
            || !hash_equals($claim->executionPlanDigest, $plan->digest())
            || $claim->stageId !== $stageId
            || $claim->attempt !== $attempt
            || $claim->candidateRevision !== $candidateRevision
            || $claim->kind !== $kind) {
            throw new RuntimeException('STALE_EVIDENCE: owner evidence does not match the current execution binding.');
        }
    }

    private function load(string $taskId, string $reference): ExecutionEvidenceRecord
    {
        $path = $this->path($taskId, $reference);
        if (!is_file($path)) {
            throw new RuntimeException('MISSING_EVIDENCE: owner evidence reference does not exist: ' . $reference);
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read execution evidence: ' . $path);
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('STALE_EVIDENCE: invalid execution evidence JSON.', 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'execution_evidence') {
            throw new RuntimeException('STALE_EVIDENCE: unsupported execution evidence schema.');
        }
        $contractRevision = $data['contract_revision'] ?? null;
        $attempt = $data['attempt'] ?? null;
        if (!is_int($contractRevision) || $contractRevision < 1 || !is_int($attempt) || $attempt < 1) {
            throw new RuntimeException('STALE_EVIDENCE: invalid execution evidence revision or attempt.');
        }
        $kind = ExecutionEvidenceKind::tryFrom(ExecutionArtifactValue::string($data['evidence_kind'] ?? null, $path . '#evidence_kind'));
        if (!$kind instanceof ExecutionEvidenceKind) {
            throw new RuntimeException('STALE_EVIDENCE: unsupported execution evidence kind.');
        }
        $claim = new ExecutionEvidenceClaim(
            ExecutionArtifactValue::string($data['task_id'] ?? null, $path . '#task_id'),
            ExecutionArtifactValue::string($data['run_id'] ?? null, $path . '#run_id'),
            $contractRevision,
            ExecutionArtifactValue::sha256($data['execution_plan_digest'] ?? null, $path . '#execution_plan_digest'),
            ExecutionArtifactValue::string($data['stage_id'] ?? null, $path . '#stage_id'),
            $attempt,
            ExecutionArtifactValue::string($data['candidate_revision'] ?? null, $path . '#candidate_revision'),
            $kind,
            ExecutionArtifactValue::string($data['source_reference'] ?? null, $path . '#source_reference'),
            ExecutionArtifactValue::sha256($data['source_digest'] ?? null, $path . '#source_digest'),
        );
        $record = new ExecutionEvidenceRecord(
            ExecutionArtifactValue::string($data['reference'] ?? null, $path . '#reference'),
            $claim,
            ExecutionArtifactValue::string($data['recorded_at'] ?? null, $path . '#recorded_at'),
        );
        $expected = $this->referenceFor($claim);
        if (!hash_equals($expected, $record->reference) || !hash_equals($reference, $record->reference)) {
            throw new RuntimeException('STALE_EVIDENCE: execution evidence reference does not match persisted content.');
        }

        return $record;
    }

    private function path(string $taskId, string $reference): string
    {
        if (preg_match('/^execution-evidence:sha256:([a-f0-9]{64})$/', $reference, $matches) !== 1) {
            throw new RuntimeException('MISSING_EVIDENCE: invalid owner evidence reference: ' . $reference);
        }

        return (new ProjectLayout($this->rootPath))->executionEvidenceRoot($taskId) . '/' . $matches[1] . '.json';
    }
}
