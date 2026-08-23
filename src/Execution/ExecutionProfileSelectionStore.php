<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

final readonly class ExecutionProfileSelectionStore
{
    public function __construct(private string $rootPath)
    {
    }

    public function select(string $taskId, ExecutionProfileName $profile, string $actor): ExecutionProfileSelection
    {
        $actor = trim($actor);
        if ($actor === '') {
            throw new RuntimeException('Execution profile selection requires a non-empty actor.');
        }

        $contract = (new TaskContractStore($this->rootPath))->load($taskId);
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('Execution profile selection requires an approved Contract.');
        }
        if ((new GovernedRunStore($this->rootPath))->findForContract($contract) !== null) {
            throw new RuntimeException(
                'Execution profile cannot change after the governed Run exists. Supersede the Contract first.',
            );
        }

        $selection = new ExecutionProfileSelection(
            $taskId,
            $contract->revision,
            $this->contractSource($contract),
            $profile,
            $actor,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            $this->path($taskId),
        );
        $this->write($selection);

        return $selection;
    }

    public function resolve(TaskContract $contract): ExecutionProfileName
    {
        $selection = $this->find($contract->taskId);
        if ($selection === null) {
            return ExecutionProfileName::MANUAL;
        }
        if ($selection->contractRevision !== $contract->revision || $selection->contractSource !== $this->contractSource($contract)) {
            throw new RuntimeException(sprintf(
                'Execution profile selection for task %s is stale for Contract revision %d. Select a profile again or remove the stale selection.',
                $contract->taskId,
                $contract->revision,
            ));
        }

        return $selection->profile;
    }

    public function find(string $taskId): ?ExecutionProfileSelection
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read execution profile selection: ' . $path);
        }

        try {
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid execution profile selection JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'execution_profile_selection') {
            throw new RuntimeException('Unsupported execution profile selection schema in ' . $path . '.');
        }
        $storedTaskId = $this->requiredString($data, 'task_id', $path);
        if ($storedTaskId !== $taskId) {
            throw new RuntimeException('Execution profile selection task id does not match requested task.');
        }
        $revision = $data['contract_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Execution profile selection requires positive contract_revision.');
        }
        $source = $data['contract_source'] ?? null;
        if (!is_array($source)) {
            throw new RuntimeException('Execution profile selection requires contract_source.');
        }
        $sourcePath = $this->requiredString($source, 'path', $path . '#contract_source');
        $sourceSha = ExecutionArtifactValue::sha256(
            $source['sha256'] ?? null,
            $path . '#contract_source.sha256',
        );
        $profile = ExecutionProfileName::tryFrom($this->requiredString($data, 'profile', $path));
        if (!$profile instanceof ExecutionProfileName) {
            throw new RuntimeException('Unsupported execution profile in ' . $path . '.');
        }

        return new ExecutionProfileSelection(
            $taskId,
            $revision,
            ['path' => $sourcePath, 'sha256' => $sourceSha],
            $profile,
            $this->requiredString($data, 'selected_by', $path),
            $this->requiredString($data, 'selected_at', $path),
            $path,
        );
    }

    public function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->executionProfileSelectionPath($taskId);
    }

    /** @return array{path: non-empty-string, sha256: non-empty-string} */
    private function contractSource(TaskContract $contract): array
    {
        $sha = hash_file('sha256', $contract->path);
        if ($sha === false) {
            throw new RuntimeException('Unable to hash Contract for execution profile selection: ' . $contract->path);
        }
        $path = PathResolver::relativeTo($this->rootPath, $contract->path);
        if ($path === '') {
            throw new RuntimeException('Unable to resolve Contract path for execution profile selection.');
        }

        return ['path' => $path, 'sha256' => 'sha256:' . $sha];
    }

    private function write(ExecutionProfileSelection $selection): void
    {
        $directory = dirname($selection->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create execution profile selection directory: ' . $directory);
        }
        $tmp = $selection->path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($selection->toArray())) === false || !rename($tmp, $selection->path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('Unable to write execution profile selection: ' . $selection->path);
        }
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
