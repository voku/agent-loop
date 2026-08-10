<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\Run\CanonicalJson;

final class TaskContractStore
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags
     * @param list<string> $behaviorAnchors
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $operatingPrompts
     */
    public function create(
        string $taskId,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        string $plannedBy,
        ?string $baseCommit = null,
        array $tags = [],
        array $behaviorAnchors = [],
        ?string $operatingPromptManifest = null,
        array $operatingPrompts = [],
    ): TaskContract {
        if ($this->find($taskId) !== null) {
            throw new RuntimeException('Task ' . $taskId . ' already has a Contract. Use revise instead.');
        }

        $now = $this->now();
        $contract = $this->newContract(
            $taskId,
            $goal,
            $scope,
            $nonGoals,
            $validation,
            TaskContract::CANDIDATE,
            1,
            $now,
            $now,
            $plannedBy,
            $baseCommit,
            $tags,
            $behaviorAnchors,
            $operatingPromptManifest,
            $operatingPrompts,
        );
        $this->write($contract);

        return $contract;
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags
     * @param list<string> $behaviorAnchors
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $operatingPrompts
     */
    public function revise(
        string $taskId,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        string $plannedBy,
        ?string $baseCommit = null,
        array $tags = [],
        array $behaviorAnchors = [],
        ?string $operatingPromptManifest = null,
        array $operatingPrompts = [],
    ): TaskContract {
        $previous = $this->load($taskId);
        $this->archive($previous);
        $now = $this->now();
        $contract = $this->newContract(
            $taskId,
            $goal,
            $scope,
            $nonGoals,
            $validation,
            TaskContract::CANDIDATE,
            $previous->revision + 1,
            $now,
            $now,
            $plannedBy,
            $baseCommit,
            $tags,
            $behaviorAnchors,
            $operatingPromptManifest,
            $operatingPrompts,
        );
        $this->write($contract);

        return $contract;
    }

    public function approve(string $taskId, string $by): TaskContract
    {
        $contract = $this->load($taskId);
        if ($contract->status === TaskContract::APPROVED && $contract->approvedBy !== null && $contract->approvedAt !== null) {
            return $contract;
        }
        if ($contract->status !== TaskContract::CANDIDATE) {
            throw new RuntimeException(sprintf('Contract revision %d is not awaiting approval.', $contract->revision));
        }

        $by = trim($by);
        if ($by === '') {
            throw new RuntimeException('Contract approval requires --by <actor>.');
        }
        $now = $this->now();
        $approved = new TaskContract(
            $contract->taskId,
            $contract->goal,
            $contract->scope,
            $contract->nonGoals,
            $contract->validation,
            TaskContract::APPROVED,
            $contract->revision,
            $contract->createdAt,
            $now,
            $contract->path,
            $contract->plannedBy,
            $contract->baseCommit,
            $contract->tags,
            $contract->behaviorAnchors,
            $contract->operatingPromptManifest,
            $contract->operatingPrompts,
            $by,
            $now,
        );
        $this->write($approved);

        return $approved;
    }

    public function find(string $taskId): ?TaskContract
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }

        return $this->decode((string) file_get_contents($path), $path, $taskId);
    }

    public function load(string $taskId): TaskContract
    {
        return $this->find($taskId) ?? throw new RuntimeException('No Contract exists for task ' . $taskId . '.');
    }

    public function path(string $taskId): string
    {
        return rtrim($this->rootPath, '/') . '/.agent-loop/contracts/' . $taskId . '/contract.json';
    }

    private function archive(TaskContract $contract): void
    {
        $directory = dirname($contract->path) . '/history';
        $this->makeDirectory($directory);
        $superseded = new TaskContract(
            $contract->taskId,
            $contract->goal,
            $contract->scope,
            $contract->nonGoals,
            $contract->validation,
            TaskContract::SUPERSEDED,
            $contract->revision,
            $contract->createdAt,
            $this->now(),
            $directory . '/' . sprintf('contract.%03d.json', $contract->revision),
            $contract->plannedBy,
            $contract->baseCommit,
            $contract->tags,
            $contract->behaviorAnchors,
            $contract->operatingPromptManifest,
            $contract->operatingPrompts,
            $contract->approvedBy,
            $contract->approvedAt,
        );
        $this->atomicWrite($superseded->path, CanonicalJson::pretty($superseded->toArray()));
    }

    /**
     * @param list<string> $scope
     * @param list<string> $nonGoals
     * @param list<string> $validation
     * @param list<string> $tags
     * @param list<string> $behaviorAnchors
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $operatingPrompts
     */
    private function newContract(
        string $taskId,
        string $goal,
        array $scope,
        array $nonGoals,
        array $validation,
        string $status,
        int $revision,
        string $createdAt,
        string $updatedAt,
        string $plannedBy,
        ?string $baseCommit,
        array $tags,
        array $behaviorAnchors,
        ?string $operatingPromptManifest,
        array $operatingPrompts,
    ): TaskContract {
        $goal = trim($goal);
        $plannedBy = trim($plannedBy);
        if ($goal === '') {
            throw new RuntimeException('A Contract requires a non-empty --goal.');
        }
        if ($plannedBy === '') {
            throw new RuntimeException('A Contract requires a non-empty --by actor.');
        }
        $scope = $this->normalizedLines($scope);
        $validation = $this->normalizedLines($validation);
        if ($scope === []) {
            throw new RuntimeException('A Contract requires at least one scope path.');
        }
        if ($validation === []) {
            throw new RuntimeException('A Contract requires at least one validation command.');
        }

        $operatingPromptManifest = $this->optionalString($operatingPromptManifest);
        $operatingPrompts = $this->normalizedOperatingPrompts($operatingPrompts);
        if ($operatingPrompts !== [] && $operatingPromptManifest === null) {
            throw new RuntimeException('A Contract with operating prompts requires an explicit operating-prompt manifest path.');
        }

        return new TaskContract(
            $taskId,
            $goal,
            $scope,
            $this->normalizedLines($nonGoals),
            $validation,
            $status,
            $revision,
            $createdAt,
            $updatedAt,
            $this->path($taskId),
            $plannedBy,
            $this->optionalString($baseCommit),
            $this->normalizedLines($tags),
            $this->normalizedLines($behaviorAnchors),
            $operatingPromptManifest,
            $operatingPrompts,
        );
    }

    private function write(TaskContract $contract): void
    {
        $this->makeDirectory(dirname($contract->path));
        $this->atomicWrite($contract->path, CanonicalJson::pretty($contract->toArray()));
    }

    private function decode(string $json, string $path, string $expectedTaskId): TaskContract
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Contract JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'task_contract') {
            throw new RuntimeException('Unsupported Contract schema in ' . $path . '.');
        }
        $taskId = $this->requiredString($data, 'task_id', $path);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException('Contract task id does not match its requested task: ' . $path);
        }
        $status = $this->requiredString($data, 'status', $path);
        if (!in_array($status, [TaskContract::CANDIDATE, TaskContract::APPROVED, TaskContract::SUPERSEDED], true)) {
            throw new RuntimeException('Unsupported Contract status in ' . $path . ': ' . $status);
        }
        $revision = $data['revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Contract revision must be a positive integer in ' . $path . '.');
        }
        $operatingPrompts = $this->operatingPromptsField($data, $path);
        $manifest = $this->optionalStringField($data, 'operating_prompt_manifest', $path);
        if ($operatingPrompts !== [] && $manifest === null) {
            throw new RuntimeException('Contract operating prompts require operating_prompt_manifest in ' . $path . '.');
        }
        $approvedBy = $this->optionalStringField($data, 'approved_by', $path);
        $approvedAt = $this->optionalStringField($data, 'approved_at', $path);
        if ($status === TaskContract::APPROVED && ($approvedBy === null || $approvedAt === null)) {
            throw new RuntimeException('Approved Contract is missing approval metadata in ' . $path . '.');
        }
        if ($status === TaskContract::CANDIDATE && ($approvedBy !== null || $approvedAt !== null)) {
            throw new RuntimeException('Candidate Contract contains stale approval metadata in ' . $path . '.');
        }

        return new TaskContract(
            $taskId,
            $this->requiredString($data, 'goal', $path),
            $this->listField($data, 'scope', $path, true),
            $this->listField($data, 'non_goals', $path),
            $this->listField($data, 'validation', $path, true),
            $status,
            $revision,
            $this->requiredString($data, 'created_at', $path),
            $this->requiredString($data, 'updated_at', $path),
            $path,
            $this->requiredString($data, 'planned_by', $path),
            $this->optionalStringField($data, 'base_commit', $path),
            $this->listField($data, 'tags', $path),
            $this->listField($data, 'behavior_anchors', $path),
            $manifest,
            $operatingPrompts,
            $approvedBy,
            $approvedAt,
        );
    }

    /** @param array<string, mixed> $data */
    private function requiredString(array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('Contract ' . $path . ' requires non-empty ' . $key . '.');
        }

        return trim($value);
    }

    /** @param array<string, mixed> $data */
    private function optionalStringField(array $data, string $key, string $path): ?string
    {
        if (!array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }
        if (!is_string($data[$key])) {
            throw new RuntimeException('Contract ' . $path . ' field ' . $key . ' must be a string or null.');
        }

        return $this->optionalString($data[$key]);
    }

    /** @param array<string, mixed> $data @return list<string> */
    private function listField(array $data, string $key, string $path, bool $required = false): array
    {
        $value = $data[$key] ?? [];
        if (!is_array($value)) {
            throw new RuntimeException('Contract ' . $path . ' field ' . $key . ' must be an array.');
        }
        $items = [];
        foreach ($value as $item) {
            if (!is_string($item) || trim($item) === '') {
                throw new RuntimeException('Contract ' . $path . ' field ' . $key . ' must contain only non-empty strings.');
            }
            $items[] = trim($item);
        }
        $items = array_values(array_unique($items));
        if ($required && $items === []) {
            throw new RuntimeException('Contract ' . $path . ' field ' . $key . ' must not be empty.');
        }

        return $items;
    }

    /**
     * @param list<array{id: string, arguments: array<string, bool|int|string>}> $prompts
     * @return list<array{id: string, arguments: array<string, bool|int|string>}>
     */
    private function normalizedOperatingPrompts(array $prompts): array
    {
        $result = [];
        $seen = [];
        foreach ($prompts as $prompt) {
            $id = trim($prompt['id']);
            if ($id === '' || preg_match('/^[a-z][a-z0-9._-]*$/', $id) !== 1 || isset($seen[$id])) {
                throw new RuntimeException('Contract operating prompt ids must be unique and match [a-z][a-z0-9._-]*.');
            }
            $arguments = $prompt['arguments'];
            ksort($arguments);
            $result[] = ['id' => $id, 'arguments' => $arguments];
            $seen[$id] = true;
        }

        return $result;
    }

    /** @param array<string, mixed> $data @return list<array{id: string, arguments: array<string, bool|int|string>}> */
    private function operatingPromptsField(array $data, string $path): array
    {
        $value = $data['operating_prompts'] ?? [];
        if (!is_array($value)) {
            throw new RuntimeException('Contract ' . $path . ' operating_prompts must be an array.');
        }
        $prompts = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || !is_string($entry['id'] ?? null) || !is_array($entry['arguments'] ?? null)) {
                throw new RuntimeException('Contract ' . $path . ' has an invalid operating prompt entry.');
            }
            $arguments = [];
            foreach ($entry['arguments'] as $key => $argument) {
                if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]*$/', $key) !== 1 || (!is_bool($argument) && !is_int($argument) && !is_string($argument))) {
                    throw new RuntimeException('Contract ' . $path . ' has an invalid operating prompt argument.');
                }
                $arguments[$key] = $argument;
            }
            $prompts[] = ['id' => $entry['id'], 'arguments' => $arguments];
        }

        return $this->normalizedOperatingPrompts($prompts);
    }

    /** @param list<string> $values @return list<string> */
    private function normalizedLines(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map('trim', $values),
            static fn (string $value): bool => $value !== '',
        )));
    }

    private function optionalString(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }

    private function makeDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create Contract directory: ' . $directory);
        }
    }

    private function atomicWrite(string $path, string $contents): void
    {
        $this->makeDirectory(dirname($path));
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contents) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write Contract artifact: ' . $path);
        }
    }
}
