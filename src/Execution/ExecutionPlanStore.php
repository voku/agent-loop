<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use JsonException;
use RuntimeException;
use voku\AgentLoop\GitWorkTree;
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
            $this->contractSource($run),
            $this->effectiveBaseCommit($contract, $profileName),
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
            if (!is_bool($mayMutate)) {
                throw new RuntimeException('Execution plan role entry has invalid may_mutate in ' . $path . '.');
            }
            $roles[] = new ExecutionRole(
                $this->requiredString($entry, 'id', $path . '#roles[' . $index . ']'),
                $mayMutate,
                ExecutionArtifactValue::stringList($capabilities, $path . '#roles[' . $index . '].required_capabilities'),
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
            $entryPath = $path . '#stages[' . $index . ']';
            $kind = ExecutionStageKind::tryFrom($this->requiredString($entry, 'kind', $entryPath));
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
            $transitionData = $entry['transitions'] ?? null;
            if (!is_array($transitionData)) {
                throw new RuntimeException('Execution stage transitions must be an object in ' . $path . '.');
            }
            $transitions = [];
            foreach ($transitionData as $outcome => $next) {
                if (!is_string($outcome) || StageOutcome::tryFrom($outcome) === null) {
                    throw new RuntimeException('Execution stage transition uses unsupported outcome in ' . $path . '.');
                }
                $transitions[$outcome] = $next === null
                    ? null
                    : ExecutionArtifactValue::string($next, $entryPath . '.transitions.' . $outcome);
            }

            $stages[] = new ExecutionStage(
                $this->requiredString($entry, 'id', $entryPath),
                $kind,
                is_string($role) ? trim($role) : null,
                $mayMutate,
                ExecutionArtifactValue::stringList($entry['requires'] ?? null, $entryPath . '.requires'),
                $transitions,
            );
        }

        return $stages;
    }

    /** @return array{path: non-empty-string, sha256: non-empty-string} */
    private function contractSource(GovernedRun $run): array
    {
        return [
            'path' => ExecutionArtifactValue::string($run->contractSource['path'], 'Governed Run contract_source.path'),
            'sha256' => ExecutionArtifactValue::sha256($run->contractSource['sha256'], 'Governed Run contract_source.sha256'),
        ];
    }

    private function effectiveBaseCommit(TaskContract $contract, ExecutionProfileName $profile): ?string
    {
        if ($profile === ExecutionProfileName::MANUAL) {
            return $contract->baseCommit;
        }

        $baseCommit = $contract->baseCommit ?? GitWorkTree::headCommit($this->rootPath);
        if ($baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException(
                'External execution profile ' . $profile->value
                . ' requires an exact Git base commit. Provide --base-commit or prepare the Run inside a Git worktree with HEAD.',
            );
        }

        return $baseCommit;
    }

    private function contractSha(TaskContract $contract): string
    {
        $sha = hash_file('sha256', $contract->path);
        if ($sha === false) {
            throw new RuntimeException('Unable to hash approved Contract for execution planning.');
        }

        return 'sha256:' . $sha;
    }

    /**
     * @param array<string, mixed> $data
     * @return non-empty-string
     */
    private function requiredString(array $data, string $key, string $path): string
    {
        return ExecutionArtifactValue::string($data[$key] ?? null, $path . '#' . $key);
    }
}
