<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

final class GovernedRunStore
{
    public function __construct(private readonly string $rootPath)
    {
    }

    /**
     * @param string $learningRoot absolute path of the durable Learning repository this Run is governed against.
     *                             What gets persisted is a portable reference to it, never this absolute path.
     */
    public function prepare(TaskContract $contract, Session $session, string $learningRoot): GovernedRun
    {
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('A governed Run requires an approved Contract.');
        }
        if ($session->taskId !== $contract->taskId) {
            throw new RuntimeException('Session task does not match approved Contract task.');
        }
        $learningRootReference = $this->portableLearningRoot($learningRoot);

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
            $sameContract = $existing->contractRevision === $contract->revision
                && $existing->contractSource === $contractSource;
            if ($sameContract) {
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
                        $existing->learningRoot ?? 'the project-configured location',
                        $learningRootReference ?? 'the project-configured location',
                    ));
                }

                return $existing;
            }

            if ($contract->revision <= $existing->contractRevision) {
                throw new RuntimeException(sprintf(
                    'Existing governed Run %s is bound to Contract revision %d; current approved revision is %d.',
                    $existing->runId,
                    $existing->contractRevision,
                    $contract->revision,
                ));
            }
            if ($existing->sessionId === $session->id) {
                throw new RuntimeException(sprintf(
                    'Cannot supersede governed Run %s with Contract revision %d while reusing its Session %s.',
                    $existing->runId,
                    $contract->revision,
                    $session->id,
                ));
            }
            $this->assertSupersededSessionIsClosed($existing);

            return $this->replaceSupersededRun(
                $existing,
                $contract,
                $contractSource,
                $session,
                $learningRootReference,
            );
        }

        $run = $this->newRun($contract, $contractSource, $session, $learningRootReference);
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

    /** @param array{path: string, sha256: string} $contractSource */
    private function newRun(
        TaskContract $contract,
        array $contractSource,
        Session $session,
        ?string $learningRootReference,
    ): GovernedRun {
        return new GovernedRun(
            'run:' . $contract->taskId . ':' . bin2hex(random_bytes(8)),
            $contract->taskId,
            $contract->revision,
            $contractSource,
            $session->id,
            $learningRootReference,
            (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            $this->path($contract->taskId),
        );
    }

    private function assertSupersededSessionIsClosed(GovernedRun $run): void
    {
        $layout = new ProjectLayout($this->rootPath);
        $sessions = new SessionStore();
        if (!$sessions->exists($layout->sessionsRoot(), $run->sessionId)) {
            return;
        }

        $session = $sessions->load($layout->sessionsRoot(), $run->sessionId);
        if (!$session->status->isClosed()) {
            throw new RuntimeException(sprintf(
                'Cannot supersede governed Run %s while its Session %s is %s. Close or drop the Session first.',
                $run->runId,
                $session->id,
                $session->status->value,
            ));
        }
    }

    /** @param array{path: string, sha256: string} $contractSource */
    private function replaceSupersededRun(
        GovernedRun $existing,
        TaskContract $contract,
        array $contractSource,
        Session $session,
        ?string $learningRootReference,
    ): GovernedRun {
        $replacement = $this->newRun($contract, $contractSource, $session, $learningRootReference);
        $archiveDirectory = $this->archive($existing);

        try {
            $this->writeSupersession($archiveDirectory . '/supersession.json', $existing, $replacement, $contract);
            $this->write($replacement);
        } catch (RuntimeException $exception) {
            $this->restoreArchive($archiveDirectory, dirname($existing->path), $exception);
            throw $exception;
        }

        return $replacement;
    }

    private function archive(GovernedRun $run): string
    {
        $layout = new ProjectLayout($this->rootPath);
        $archiveRoot = $layout->runHistoryRoot($run->taskId);
        if (!is_dir($archiveRoot) && !mkdir($archiveRoot, 0o775, true) && !is_dir($archiveRoot)) {
            throw new RuntimeException('Unable to create governed Run history directory: ' . $archiveRoot);
        }

        $runPathSegment = preg_replace('/[^A-Za-z0-9._-]+/', '-', $run->runId);
        if (!is_string($runPathSegment) || trim($runPathSegment, '-.') === '') {
            throw new RuntimeException('Unable to derive archive path from governed Run id: ' . $run->runId);
        }
        $archiveDirectory = $archiveRoot . '/' . trim($runPathSegment, '-');
        if (file_exists($archiveDirectory)) {
            throw new RuntimeException('Governed Run archive already exists: ' . $archiveDirectory);
        }

        $currentDirectory = dirname($run->path);
        if (!rename($currentDirectory, $archiveDirectory)) {
            throw new RuntimeException(sprintf(
                'Unable to archive governed Run %s from %s to %s.',
                $run->runId,
                $currentDirectory,
                $archiveDirectory,
            ));
        }

        return $archiveDirectory;
    }

    private function restoreArchive(string $archiveDirectory, string $currentDirectory, RuntimeException $cause): void
    {
        if (is_dir($currentDirectory)) {
            $entries = array_values(array_diff(scandir($currentDirectory) ?: [], ['.', '..']));
            if ($entries !== [] || !rmdir($currentDirectory)) {
                throw new RuntimeException(
                    'Unable to restore superseded governed Run after replacement failure because the current Run directory is not empty: '
                    . $currentDirectory,
                    0,
                    $cause,
                );
            }
        }
        if (!rename($archiveDirectory, $currentDirectory)) {
            throw new RuntimeException(
                'Unable to restore superseded governed Run after replacement failure: ' . $archiveDirectory,
                0,
                $cause,
            );
        }

        $supersessionPath = $currentDirectory . '/supersession.json';
        if (is_file($supersessionPath) && !unlink($supersessionPath)) {
            throw new RuntimeException(
                'Governed Run was restored after replacement failure, but supersession metadata could not be removed: '
                . $supersessionPath,
                0,
                $cause,
            );
        }
    }

    private function writeSupersession(
        string $path,
        GovernedRun $existing,
        GovernedRun $replacement,
        TaskContract $contract,
    ): void {
        $document = [
            'schema_version' => '1.0',
            'kind' => 'governed_run_supersession',
            'task_id' => $existing->taskId,
            'superseded_run_id' => $existing->runId,
            'replacement_run_id' => $replacement->runId,
            'superseded_contract_revision' => $existing->contractRevision,
            'replacement_contract_revision' => $replacement->contractRevision,
            'approved_by' => $contract->approvedBy,
            'approved_at' => $contract->approvedAt,
            'recorded_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        ];
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($document)) === false || !rename($tmp, $path)) {
            if (is_file($tmp) && !unlink($tmp)) {
                throw new RuntimeException('Unable to remove temporary governed Run supersession artifact: ' . $tmp);
            }
            throw new RuntimeException('Unable to write governed Run supersession artifact: ' . $path);
        }
    }

    private function write(GovernedRun $run): void
    {
        $directory = dirname($run->path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create governed Run directory: ' . $directory);
        }
        $tmp = $run->path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($run->toArray())) === false || !rename($tmp, $run->path)) {
            if (is_file($tmp) && !unlink($tmp)) {
                throw new RuntimeException('Unable to remove temporary governed Run artifact: ' . $tmp);
            }
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
            $this->optionalLearningRoot($data, $path),
            $this->requiredString($data, 'prepared_at', $path),
            $path,
        );
    }

    /**
     * A Learning repository inside the project is recorded project-relative, which survives a clone.
     * One outside the project has no portable path, so the Run records that its location is
     * configuration-owned and re-resolves it through ProjectLayout on every read.
     */
    private function portableLearningRoot(string $learningRoot): ?string
    {
        $normalized = rtrim(str_replace('\\', '/', $learningRoot), '/');
        if ($normalized === '') {
            throw new RuntimeException('A governed Run requires a durable Learning root.');
        }

        $reference = PathResolver::relativeTo($this->rootPath, $normalized);
        if (!PathResolver::isAbsolute($reference)) {
            return $reference;
        }

        $configured = (new ProjectLayout($this->rootPath))->learningRoot();
        if (rtrim(str_replace('\\', '/', $configured), '/') !== $normalized) {
            throw new RuntimeException(sprintf(
                'Learning root %s is outside the project and is not the configured one, so no governed Run could '
                . 'record it portably. Set paths.learning_root in .agent-loop/init.json instead of passing it per command.',
                $normalized,
            ));
        }

        return null;
    }

    /** @param array<string, mixed> $data */
    private function optionalLearningRoot(array $data, string $path): ?string
    {
        if (!array_key_exists('learning_root', $data)) {
            throw new RuntimeException($path . ' requires non-empty learning_root.');
        }
        $value = $data['learning_root'];
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '' || PathResolver::isAbsolute($value)) {
            throw new RuntimeException($path . ' learning_root must be a project-relative path or null.');
        }

        return trim($value);
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
