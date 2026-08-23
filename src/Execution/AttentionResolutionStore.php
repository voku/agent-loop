<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;

final readonly class AttentionResolutionStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function record(ExecutionPlan $plan, AttentionRequest $attention, string $actor): AttentionResolution
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new RuntimeException('Attention resolution requires a non-empty human actor.');
        }
        if ($attention->taskId !== $plan->taskId || $attention->runId !== $plan->runId) {
            throw new RuntimeException('Attention resolution does not match the current governed Run.');
        }

        $path = $this->path($plan->taskId, $attention->id);
        if (is_file($path)) {
            $existing = $this->load($plan->taskId, $attention->id);
            if ($existing->taskId !== $plan->taskId
                || $existing->runId !== $plan->runId
                || $existing->contractRevision !== $plan->contractRevision
                || !hash_equals($existing->executionPlanDigest, $plan->digest())
                || $existing->attentionId !== $attention->id
                || $existing->stageId !== $attention->stageId
                || $existing->resolvedBy !== $actor) {
                throw new RuntimeException('Attention already has a different authoritative resolution record.');
            }

            return $existing;
        }

        $resolution = new AttentionResolution(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            $attention->id,
            $attention->stageId,
            $actor,
            (new DateTimeImmutable())->format(DATE_ATOM),
        );
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Attention resolution directory: ' . $directory);
        }
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, CanonicalJson::pretty($resolution->toArray())) === false || !rename($temporary, $path)) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw new RuntimeException('Unable to persist Attention resolution: ' . $path);
        }

        return $resolution;
    }

    public function load(string $taskId, string $attentionId): AttentionResolution
    {
        $path = $this->path($taskId, $attentionId);
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Authoritative Attention resolution record is missing: ' . $path);
        }
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Attention resolution JSON: ' . $path, 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'execution_attention_resolution') {
            throw new RuntimeException('Unsupported Attention resolution record: ' . $path);
        }

        $contractRevision = $data['contract_revision'] ?? null;
        $stageId = $data['stage_id'] ?? null;
        if (!is_int($contractRevision) || ($stageId !== null && !is_string($stageId))) {
            throw new RuntimeException('Attention resolution record has invalid typed fields: ' . $path);
        }

        return new AttentionResolution(
            $this->string($data, 'task_id', $path),
            $this->string($data, 'run_id', $path),
            $contractRevision,
            $this->string($data, 'execution_plan_digest', $path),
            $this->string($data, 'attention_id', $path),
            $stageId,
            $this->string($data, 'resolved_by', $path),
            $this->string($data, 'resolved_at', $path),
        );
    }

    public function path(string $taskId, string $attentionId): string
    {
        return (new ProjectLayout($this->rootPath))->attentionResolutionPath($taskId, $attentionId);
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Attention resolution field %s is invalid in %s.', $key, $path));
        }

        return trim($value);
    }
}
