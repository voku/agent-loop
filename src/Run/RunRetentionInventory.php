<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use DateTimeImmutable;
use FilesystemIterator;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use voku\AgentLoop\ProjectLayout;

/**
 * A read-only inventory. Neither age nor supersession establishes permission to archive.
 */
final readonly class RunRetentionInventory
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array{
     *   schema_version: string, kind: string,
     *   policy: array{minimum_age_days: int},
     *   totals: array{current: int, history: int, files: int, bytes: int, age_threshold_met: int},
     *   records: list<array{
     *     path: string, task_id: string, scope: 'current'|'history',
     *     run_id: string|null, age_days: int|null, age_threshold_met: bool,
     *     file_count: int, bytes: int, run_document: 'valid'|'missing'|'invalid',
     *     retention_decision: 'blocked', reasons: list<string>
     *   }>
     * }
     */
    public function inspect(int $minimumAgeDays = 90, ?DateTimeImmutable $now = null): array
    {
        if ($minimumAgeDays < 0) {
            throw new RuntimeException('Retention age must not be negative.');
        }
        $layout = new ProjectLayout($this->rootPath);
        $runsRoot = $layout->runsRoot();
        $historyRoot = $layout->runsHistoryRoot();
        $now ??= new DateTimeImmutable();
        $records = [];

        foreach ($this->entries($runsRoot) as $taskId) {
            if (str_starts_with($taskId, '.')) {
                continue;
            }
            $directory = $runsRoot . '/' . $taskId;
            if (is_dir($directory) || is_link($directory)) {
                $records[] = $this->inspectRun($directory, $taskId, $taskId, 'current', $minimumAgeDays, $now);
            }
        }

        foreach ($this->entries($historyRoot) as $taskId) {
            $taskHistory = $historyRoot . '/' . $taskId;
            if (is_link($taskHistory)) {
                $records[] = $this->inspectRun($taskHistory, '.history/' . $taskId, $taskId, 'history', $minimumAgeDays, $now);

                continue;
            }
            if (!is_dir($taskHistory)) {
                continue;
            }
            foreach ($this->entries($taskHistory) as $runDirectoryName) {
                $directory = $taskHistory . '/' . $runDirectoryName;
                if (is_dir($directory) || is_link($directory)) {
                    $records[] = $this->inspectRun(
                        $directory,
                        '.history/' . $taskId . '/' . $runDirectoryName,
                        $taskId,
                        'history',
                        $minimumAgeDays,
                        $now,
                    );
                }
            }
        }

        $totals = ['current' => 0, 'history' => 0, 'files' => 0, 'bytes' => 0, 'age_threshold_met' => 0];
        foreach ($records as $record) {
            ++$totals[$record['scope']];
            $totals['files'] += $record['file_count'];
            $totals['bytes'] += $record['bytes'];
            if ($record['age_threshold_met']) {
                ++$totals['age_threshold_met'];
            }
        }

        return [
            'schema_version' => '1.0',
            'kind' => 'governed_run_retention_inventory',
            'policy' => ['minimum_age_days' => $minimumAgeDays],
            'totals' => $totals,
            'records' => $records,
        ];
    }

    /**
     * @param 'current'|'history' $scope
     * @return array{
     *   path: string, task_id: string, scope: 'current'|'history',
     *   run_id: string|null, age_days: int|null, age_threshold_met: bool,
     *   file_count: int, bytes: int, run_document: 'valid'|'missing'|'invalid',
     *   retention_decision: 'blocked', reasons: list<string>
     * }
     */
    private function inspectRun(
        string $directory,
        string $path,
        string $taskId,
        string $scope,
        int $minimumAgeDays,
        DateTimeImmutable $now,
    ): array {
        $reasons = [
            $scope === 'current' ? 'current_lifecycle_unverified' : 'historical_lifecycle_unverified',
            'cross_owner_references_unverified',
            'full_evidence_integrity_unverified',
        ];
        $fileCount = 0;
        $bytes = 0;
        $runId = null;
        $ageDays = null;
        $status = 'missing';

        if (is_link($directory)) {
            $reasons[] = 'symlink_run_directory';
        } elseif (!is_dir($directory)) {
            $reasons[] = 'missing_run_directory';
        } else {
            [$fileCount, $bytes] = $this->measure($directory, $reasons);
            $runFile = $directory . '/run.json';
            if (is_link($runFile)) {
                $status = 'invalid';
                $reasons[] = 'symlink_run_document';
            } elseif (!is_file($runFile)) {
                $reasons[] = 'missing_run_document';
            } else {
                $status = 'invalid';
                try {
                    $document = $this->readJson($runFile);
                    if (!$this->validRun($document, $taskId)) {
                        $reasons[] = 'invalid_run_identity_or_schema';
                    } else {
                        $status = 'valid';
                        $runId = (string) $document['run_id'];
                        try {
                            $date = new DateTimeImmutable((string) $document['prepared_at']);
                            $difference = $now->getTimestamp() - $date->getTimestamp();
                            if ($difference < 0) {
                                $reasons[] = 'future_preparation_time';
                            } else {
                                $ageDays = intdiv($difference, 86400);
                            }
                        } catch (Throwable) {
                            $reasons[] = 'invalid_preparation_time';
                        }
                        if ($scope === 'history') {
                            $this->inspectSupersession($directory, $taskId, $runId, $reasons);
                        }
                    }
                } catch (RuntimeException) {
                    $reasons[] = 'malformed_run_document';
                }
            }
        }

        return [
            'path' => $path,
            'task_id' => $taskId,
            'scope' => $scope,
            'run_id' => $runId,
            'age_days' => $ageDays,
            'age_threshold_met' => $ageDays !== null && $ageDays >= $minimumAgeDays,
            'file_count' => $fileCount,
            'bytes' => $bytes,
            'run_document' => $status,
            'retention_decision' => 'blocked',
            'reasons' => array_values(array_unique($reasons)),
        ];
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        if (is_link($directory)) {
            throw new RuntimeException('Run inventory refuses symlink root: ' . $directory);
        }
        if (!is_dir($directory)) {
            return [];
        }
        $entries = scandir($directory);
        if ($entries === false) {
            throw new RuntimeException('Unable to enumerate Run root: ' . $directory);
        }

        return array_values(array_filter(
            $entries,
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
    }

    /**
     * @param list<string> $reasons
     * @return array{int, int}
     */
    private function measure(string $directory, array &$reasons): array
    {
        $files = 0;
        $bytes = 0;
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            );
            foreach ($iterator as $item) {
                if ($item->isLink()) {
                    $reasons[] = 'symlink_evidence';

                    continue;
                }
                if ($item->isFile()) {
                    ++$files;
                    $bytes += $item->getSize();
                }
            }
        } catch (Throwable) {
            $reasons[] = 'unreadable_evidence_tree';
        }

        return [$files, $bytes];
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        if (is_link($file) || !is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Run evidence is not a readable regular file: ' . $file);
        }
        $raw = file_get_contents($file);
        if ($raw === false) {
            throw new RuntimeException('Cannot read Run evidence: ' . $file);
        }
        try {
            $document = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Run evidence JSON: ' . $file, 0, $exception);
        }

        if (!is_array($document) || array_is_list($document)) {
            throw new RuntimeException('Run evidence must be a JSON object: ' . $file);
        }

        return $document;
    }

    /** @param array<string, mixed> $data */
    private function validRun(array $data, string $taskId): bool
    {
        $source = $data['contract_source'] ?? null;

        return ($data['schema_version'] ?? null) === '1.0'
            && ($data['kind'] ?? null) === 'governed_run'
            && ($data['task_id'] ?? null) === $taskId
            && is_string($data['run_id'] ?? null) && $data['run_id'] !== ''
            && is_int($data['contract_revision'] ?? null) && $data['contract_revision'] > 0
            && is_string($data['session_id'] ?? null) && $data['session_id'] !== ''
            && is_string($data['prepared_at'] ?? null) && $data['prepared_at'] !== ''
            && array_key_exists('learning_root', $data)
            && ($data['learning_root'] === null || (is_string($data['learning_root']) && $data['learning_root'] !== ''))
            && is_array($source)
            && is_string($source['path'] ?? null) && $source['path'] !== ''
            && is_string($source['sha256'] ?? null)
            && preg_match('/^sha256:[a-f0-9]{64}$/', $source['sha256']) === 1;
    }

    /** @param list<string> $reasons */
    private function inspectSupersession(string $directory, string $taskId, string $runId, array &$reasons): void
    {
        $file = $directory . '/supersession.json';
        if (!is_file($file) || is_link($file)) {
            $reasons[] = 'missing_or_symlink_supersession';

            return;
        }
        try {
            $supersession = $this->readJson($file);
            if (($supersession['kind'] ?? null) !== 'governed_run_supersession'
                || ($supersession['task_id'] ?? null) !== $taskId
                || ($supersession['superseded_run_id'] ?? null) !== $runId
                || !is_string($supersession['replacement_run_id'] ?? null)
                || $supersession['replacement_run_id'] === ''
            ) {
                $reasons[] = 'invalid_supersession_evidence';
            }
        } catch (RuntimeException) {
            $reasons[] = 'invalid_supersession_evidence';
        }
    }
}
