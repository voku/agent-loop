<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentSession\Session;
use voku\AgentLoop\ProjectLayout;

final class GovernedRunStore
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @param string $learningRoot absolute path of the durable Learning repository this Run is governed against
     */
    public function prepare(TaskContract $contract, Session $session, string $learningRoot): GovernedRun
    {
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('A governed Run requires an approved Contract.');
        }
        if ($session->taskId !== $contract->taskId) {
            throw new RuntimeException('Session task does not match approved Contract task.');
        }
        $learningRootReference = PathResolver::relativeTo($this->rootPath, rtrim($learningRoot, '/'));
        if (trim($learningRootReference) === '') {
            throw new RuntimeException('A governed Run requires a durable Learning root.');
        }

        $contractHash = hash_file('sha256', $contract->path);
        if ($contractHash === false) {
            throw new RuntimeException('Unable to hash approved Contract: ' . $contract->path);
        }
        $contractSource = [
            'path' => RelativePath::fromRoot($this->rootPath, $contract->path),
            'sha256' => 'sha256:' . $contractHash,
        ];

        $existing = $this->find($contract->taskId);
        if ($existing !== null) {
            if ($existing->contractRevision !== $contract->revision || $existing->contractSource !== $contractSource) {
                throw new RuntimeException(sprintf(
                    'Existing governed Run %s is bound to Contract revision %d; current approved revision is %d.',
                    $existing->runId,
                    $existing->contractRevision,
                    $contract->revision,
                ));
            }
            if ($existing->sessionId !== $session->id) {
                throw new RuntimeException(sprintf(
                    'Existing governed Run %s is bound to Session %s, not %s.',
                    $existing->runId,
                    $existing->sessionId,
                    $session->id,
                ));
            }
            if ($existing->learningRoot !== $learningRootReference) {
                throw new RuntimeException(sprintf(
                    'Existing governed Run %s is governed against Learning root %s, not %s.',
                    $existing->runId,
                    $existing->learningRoot,
                    $learningRootReference,
                ));
            }

            return $existing;
        }

        $run = new GovernedRun(
            'run:' . $contract->taskId . ':' . bin2hex(random_bytes(8)),
            $contract->taskId,
            $contract->revision,
            $contractSource,
            $session->id,
            $learningRootReference,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            $this->path($contract->taskId),
        );
        $this->write($run);

        return $run;
    }

    public function find(string $taskId): ?GovernedRun
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read governed Run artifact: ' . $path);
        }

        return $this->decode($contents, $path, $taskId);
    }

    public function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->runRoot($taskId) . '/run.json';
    }

    private function write(GovernedRun $run): void
    {
        $directory = dirname($run->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create governed Run directory: ' . $directory);
        }
        $tmp = $run->path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($run->toArray())) === false || !rename($tmp, $run->path)) {
            @unlink($tmp);
            throw new RuntimeException('Unable to write governed Run artifact: ' . $run->path);
        }
    }

    private function decode(string $json, string $path, string $expectedTaskId): GovernedRun
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid governed Run JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'governed_run') {
            throw new RuntimeException('Unsupported governed Run schema in ' . $path . '.');
        }

        $taskId = $this->requiredString($data, 'task_id', $path);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException('Governed Run task id does not match requested task: ' . $path);
        }
        $revision = $data['contract_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Governed Run contract_revision must be a positive integer in ' . $path . '.');
        }
        $source = $data['contract_source'] ?? null;
        if (!is_array($source)) {
            throw new RuntimeException('Governed Run contract_source must be an object in ' . $path . '.');
        }
        $sourcePath = $this->requiredString($source, 'path', $path . '#contract_source');
        $sourceHash = $this->requiredString($source, 'sha256', $path . '#contract_source');
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $sourceHash) !== 1) {
            throw new RuntimeException('Governed Run contract_source.sha256 is invalid in ' . $path . '.');
        }

        return new GovernedRun(
            $this->requiredString($data, 'run_id', $path),
            $taskId,
            $revision,
            ['path' => $sourcePath, 'sha256' => $sourceHash],
            $this->requiredString($data, 'session_id', $path),
            $this->requiredString($data, 'learning_root', $path),
            $this->requiredString($data, 'prepared_at', $path),
            $path,
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
}
