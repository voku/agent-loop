<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;

/** @internal Owner-side persistence used by the human workflow Attention transition. */
final readonly class AttentionResolutionStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function record(
        ExecutionPlan $plan,
        AttentionRequest $attention,
        int $attempt,
        string $actor,
        ?string $response = null,
    ): AttentionResolution {
        if ($attention->taskId !== $plan->taskId || $attention->runId !== $plan->runId || $attention->stageId === null) {
            throw new RuntimeException('ATTENTION_MISMATCH: Attention does not belong to the current governed execution.');
        }
        $path = $this->path($plan->taskId, $attention->id);
        if (is_file($path)) {
            $existing = $this->load($plan->taskId, $attention->id);
            if ($existing->taskId === $plan->taskId
                && $existing->runId === $plan->runId
                && $existing->contractRevision === $plan->contractRevision
                && hash_equals($existing->executionPlanDigest, $plan->digest())
                && $existing->stageId === $attention->stageId
                && $existing->attempt === $attempt) {
                // The human decision for this exact attempt is already recorded,
                // but execution did not resume (for example the process stopped
                // between the record and the state write). That record is the
                // authoritative decision: a retry completes the transition with
                // it rather than overwriting it or failing for good. Callers read
                // the applied actor and response from the returned record.
                return $existing;
            }

            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: refusing to overwrite an existing Attention resolution record.');
        }

        $resolution = new AttentionResolution(
            $attention->id,
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            $attention->stageId,
            $attempt,
            $actor,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            $response,
        );
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Attention resolution directory: ' . $directory);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($resolution->toArray())) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('Unable to persist Attention resolution: ' . $path);
        }

        return $resolution;
    }

    public function assertCurrent(
        ExecutionPlan $plan,
        AttentionRequest $attention,
        int $attempt,
    ): void {
        $resolution = $this->load($plan->taskId, $attention->id);
        if ($resolution->taskId !== $plan->taskId
            || $resolution->runId !== $plan->runId
            || $resolution->contractRevision !== $plan->contractRevision
            || !hash_equals($resolution->executionPlanDigest, $plan->digest())
            || $attention->stageId === null
            || $resolution->stageId !== $attention->stageId
            || $resolution->attempt !== $attempt) {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: authoritative Attention resolution does not match the current execution attempt.');
        }
    }

    public function load(string $taskId, string $attentionId): AttentionResolution
    {
        $path = $this->path($taskId, $attentionId);
        $resolution = $this->loadPath($path);
        if ($resolution->taskId !== $taskId || $resolution->attentionId !== $attentionId) {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: resolution identity does not match its requested task and Attention.');
        }

        return $resolution;
    }

    /** @return list<AttentionResolution> */
    public function listForTask(string $taskId): array
    {
        $layout = new ProjectLayout($this->rootPath);
        $directory = $layout->executionAttentionResolutionsRoot($taskId);
        if (!is_dir($directory)) {
            return [];
        }
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to list Attention resolutions.');
        }
        $resolutions = [];
        foreach ($entries as $entry) {
            if (preg_match('/^[a-f0-9]{64}\.json$/D', $entry) !== 1) {
                continue;
            }
            $path = $layout->runChildFile($taskId, 'attention-resolutions/' . $entry);
            $resolution = $this->loadPath($path);
            if ($resolution->taskId !== $taskId || !hash_equals(substr($entry, 0, 64), hash('sha256', $resolution->attentionId))) {
                throw new RuntimeException('ATTENTION_RESOLUTION_STALE: resolution identity does not match its task and path.');
            }
            $resolutions[] = $resolution;
        }
        usort($resolutions, static fn (AttentionResolution $left, AttentionResolution $right): int => strcmp($left->resolvedAt, $right->resolvedAt) ?: strcmp($left->attentionId, $right->attentionId));

        return $resolutions;
    }

    private function loadPath(string $path): AttentionResolution
    {
        if (!is_file($path)) {
            throw new RuntimeException('ATTENTION_RESOLUTION_REQUIRED: human-owned Attention has no authoritative resolution record.');
        }
        $json = file_get_contents($path);
        if (!is_string($json)) {
            throw new RuntimeException('Unable to read Attention resolution: ' . $path);
        }
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: invalid Attention resolution JSON.', 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'execution_attention_resolution') {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: unsupported Attention resolution schema.');
        }
        $revision = $data['contract_revision'] ?? null;
        $attempt = $data['attempt'] ?? null;
        if (!is_int($revision) || $revision < 1 || !is_int($attempt) || $attempt < 1) {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: invalid Attention resolution revision or attempt.');
        }
        $response = $data['response'] ?? null;
        if ($response !== null && !is_string($response)) {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: invalid Attention response.');
        }

        return new AttentionResolution(
            ExecutionArtifactValue::string($data['attention_id'] ?? null, $path . '#attention_id'),
            ExecutionArtifactValue::string($data['task_id'] ?? null, $path . '#task_id'),
            ExecutionArtifactValue::string($data['run_id'] ?? null, $path . '#run_id'),
            $revision,
            ExecutionArtifactValue::sha256($data['execution_plan_digest'] ?? null, $path . '#execution_plan_digest'),
            ExecutionArtifactValue::string($data['stage_id'] ?? null, $path . '#stage_id'),
            $attempt,
            ExecutionArtifactValue::string($data['actor'] ?? null, $path . '#actor'),
            ExecutionArtifactValue::string($data['resolved_at'] ?? null, $path . '#resolved_at'),
            $response,
        );
    }

    private function path(string $taskId, string $attentionId): string
    {
        $digest = hash('sha256', $attentionId);

        return (new ProjectLayout($this->rootPath))->runChildFile(
            $taskId,
            'attention-resolutions/' . $digest . '.json',
        );
    }
}
