<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Workflow\TaskContract;

final readonly class ExecutionPlanStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function prepare(GovernedRun $run, TaskContract $contract): ExecutionPlan
    {
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('Execution plan preparation requires an approved Contract.');
        }
        if ($run->taskId !== $contract->taskId
            || $run->contractRevision !== $contract->revision
            || $run->contractSource['sha256'] !== $this->contractSha($contract)) {
            throw new RuntimeException('Governed Run does not match the approved Contract for execution planning.');
        }

        $existing = $this->find($contract->taskId);
        if ($existing !== null) {
            if ($existing->runId !== $run->runId
                || $existing->contractRevision !== $contract->revision
                || $existing->contractSource !== $run->contractSource) {
                throw new RuntimeException('Existing execution plan is not bound to the current governed Run.');
            }

            return $existing;
        }

        $profileName = (new ExecutionProfileSelectionStore($this->rootPath))->resolve($contract);
        $plan = ExecutionPlan::resolve(
            ExecutionProfile::firstParty($profileName),
            $run->taskId,
            $run->runId,
            $run->contractRevision,
            $run->contractSource,
            $contract->baseCommit,
            $run->preparedAt,
        );
        $this->write($plan);

        return $plan;
    }

    public function find(string $taskId): ?ExecutionPlan
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read execution plan: ' . $path);
        }

        return $this->decode($contents, $path, $taskId);
    }

    public function load(string $taskId): ExecutionPlan
    {
        return $this->find($taskId) ?? throw new RuntimeException('No execution plan exists for task ' . $taskId . '.');
    }

    public function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->executionPlanPath($taskId);
    }

    private function write(ExecutionPlan $plan): void
    {
        $path = $this->path($plan->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create execution plan directory: ' . $directory);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($plan->toArray())) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('Unable to write execution plan: ' . $path);
        }
    }

    private function decode(string $json, string $path, string $expectedTaskId): ExecutionPlan
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid execution plan JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'governed_execution_plan') {
            throw new RuntimeException('Unsupported execution plan schema in ' . $path . '.');
        }
        $taskId = $this->requiredString($data, 'task_id', $path);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException('Execution plan task id does not match requested task.');
        }
        $revision = $data['contract_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Execution plan contract_revision must be a positive integer.');
        }
        $source = $data['contract_source'] ?? null;
        if (!is_array($source)) {
            throw new RuntimeException('Execution plan contract_source must be an object.');
        }
        $sourcePath = $this->requiredString($source, 'path', $path . '#contract_source');
        $sourceSha = $this->requiredString($source, 'sha256', $path . '#contract_source');
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $sourceSha) !== 1) {
            throw new RuntimeException('Execution plan contract_source.sha256 is invalid.');
        }
        $profile = ExecutionProfileName::tryFrom($this->requiredString($data, 'profile', $path));
        if (!$profile instanceof ExecutionProfileName) {
            throw new RuntimeException('Unsupported execution profile in ' . $path . '.');
        }

        $roles = $this->decodeRoles($data['roles'] ?? null, $path);
        $stages = $this->decodeStages($data['stages'] ?? null, $path);
        $baseCommit = $data['base_commit'] ?? null;
        if ($baseCommit !== null && (!is_string($baseCommit) || trim($baseCommit) === '')) {
            throw new RuntimeException('Execution plan base_commit must be a non-empty string or null.');
        }

        $plan = new ExecutionPlan(
            $taskId,
            $this->requiredString($data, 'run_id', $path),
            $revision,
            ['path' => $sourcePath, 'sha256' => $sourceSha],
            is_string($baseCommit) ? trim($baseCommit) : null,
            $profile,
            $roles,
            $stages,
            $this->requiredString($data, 'prepared_at', $path),
        );
        $digest = $this->requiredString($data, 'digest', $path);
        if (!hash_equals($plan->digest(), $digest)) {
            throw new RuntimeException('Execution plan digest does not match persisted content in ' . $path . '.');
        }

        return $plan;
    }

    /** @return list<ExecutionRole> */
    private function decodeRoles(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Execution plan roles must be an array in ' . $path . '.');
        }
        $roles = [];
        foreach ($value as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Execution plan role entry must be an object in ' . $path . '.');
            }
            $mayMutate = $entry['may_mutate'] ?? null;
            $capabilities = $entry['required_capabilities'] ?? null;
            if (!is_bool($mayMutate) || !is_array($capabilities)) {
                throw new RuntimeException('Execution plan role entry has invalid mutation/capability fields in ' . $path . '.');
            }
            $decodedCapabilities = [];
            foreach ($capabilities as $capability) {
                if (!is_string($capability) || trim($capability) === '') {
                    throw new RuntimeException('Execution role capabilities must be non-empty strings in ' . $path . '.');
                }
                $decodedCapabilities[] = trim($capability);
            }
            $roles[] = new ExecutionRole(
                $this->requiredString($entry, 'id', $path . '#roles[' . $index . ']'),
                $mayMutate,
                $decodedCapabilities,
            );
        }

        return $roles;
    }

    /** @return list<ExecutionStage> */
    private function decodeStages(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Execution plan stages must be an array in ' . $path . '.');
        }
        $stages = [];
        foreach ($value as $index => $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Execution plan stage entry must be an object in ' . $path . '.');
            }
            $kind = ExecutionStageKind::tryFrom($this->requiredString($entry, 'kind', $path . '#stages[' . $index . ']'));
            if (!$kind instanceof ExecutionStageKind) {
                throw new RuntimeException('Unsupported execution stage kind in ' . $path . '.');
            }
            $role = $entry['role'] ?? null;
            if ($role !== null && (!is_string($role) || trim($role) === '')) {
                throw new RuntimeException('Execution stage role must be a non-empty string or null in ' . $path . '.');
            }
            $mayMutate = $entry['may_mutate'] ?? null;
            if (!is_bool($mayMutate)) {
                throw new RuntimeException('Execution stage may_mutate must be boolean in ' . $path . '.');
            }
            $requires = $this->stringList($entry['requires'] ?? null, $path . '#stages[' . $index . '].requires');
            $transitionData = $entry['transitions'] ?? null;
            if (!is_array($transitionData)) {
                throw new RuntimeException('Execution stage transitions must be an object in ' . $path . '.');
            }
            $transitions = [];
            foreach ($transitionData as $outcome => $next) {
                if (!is_string($outcome) || StageOutcome::tryFrom($outcome) === null) {
                    throw new RuntimeException('Execution stage transition uses unsupported outcome in ' . $path . '.');
                }
                if ($next !== null && (!is_string($next) || trim($next) === '')) {
                    throw new RuntimeException('Execution stage transition target must be a non-empty string or null in ' . $path . '.');
                }
                $transitions[$outcome] = is_string($next) ? trim($next) : null;
            }

            $stages[] = new ExecutionStage(
                $this->requiredString($entry, 'id', $path . '#stages[' . $index . ']'),
                $kind,
                is_string($role) ? trim($role) : null,
                $mayMutate,
                $requires,
                $transitions,
            );
        }

        return $stages;
    }

    /** @return list<non-empty-string> */
    private function stringList(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new RuntimeException($path . ' must be an array.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException($path . ' must contain only non-empty strings.');
            }
            $items[] = trim($item);
        }

        return $items;
    }

    private function contractSha(TaskContract $contract): string
    {
        $sha = hash_file('sha256', $contract->path);
        if ($sha === false) {
            throw new RuntimeException('Unable to hash approved Contract for execution planning.');
        }

        return 'sha256:' . $sha;
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException($path . ' requires non-empty string ' . $key . '.');
        }

        return trim($value);
    }
}
