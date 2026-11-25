<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use voku\AgentLoop\Execution\CandidateWorkspaceIdentity;
use voku\AgentLoop\Run\CanonicalJson;

/**
 * Pre-approval scope preflight: observes a draft worktree the host validated
 * under its own permissions. Loop holds no authority to run a candidate
 * Contract's commands before approval (DeclaredValidationShell runs only
 * approved obligations), so this command never executes validation. It binds
 * the host-reported outcome to the Contract revision and intent, the base
 * commit, the draft content and the declared validation, and reports changed
 * paths outside the scope as observed drift: evidence, not proven necessity.
 *
 *   workflow preflight <task-id> start  --workspace <draft>
 *   workflow preflight <task-id> record --workspace <draft> --validation passed|failed --by <actor>
 */
final readonly class WorkflowScopePreflightCommand
{
    public function __construct(private string $rootPath)
    {
    }

    /** @param list<string> $args */
    public function run(array $args): int
    {
        try {
            $taskId = (new WorkflowTaskId($args[0] ?? ''))->value;
            $phase = $args[1] ?? '';
            $options = self::options(array_slice($args, 2));
            $workspace = $options['--workspace'] ?? throw new InvalidArgumentException('--workspace <draft> is required.');
            $contract = (new TaskContractStore($this->rootPath))->load($taskId);
            $identity = self::identity($contract, $workspace);
            $path = self::path($contract);

            if ($phase === 'start') {
                self::write($path, ['phase' => 'started', 'started' => $identity]);
                echo json_encode(['status' => 'ok', 'phase' => 'started', 'identity' => $identity], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

                return 0;
            }
            if ($phase !== 'record') {
                throw new InvalidArgumentException('Usage: workflow preflight <task-id> start|record --workspace <draft> [--validation passed|failed --by <actor>].');
            }
            $validation = $options['--validation'] ?? '';
            if (!in_array($validation, ['passed', 'failed'], true)) {
                throw new InvalidArgumentException('--validation must be passed or failed.');
            }
            $by = trim($options['--by'] ?? '');
            if ($by === '') {
                throw new InvalidArgumentException('--by <actor> is required.');
            }
            $started = self::read($path)['started'] ?? null;
            if (!is_array($started)) {
                throw new RuntimeException('No started preflight for this task; run start before the host validation.');
            }
            $record = [
                'phase' => 'recorded',
                'identity' => $identity,
                'validation' => $validation,
                'provenance' => 'host_reported',
                'recorded_by' => $by,
                'recorded_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'workspace' => $identity['workspace'],
                'drift' => self::drift($contract, (string) $identity['draft']),
            ];
            $comparable = $identity;
            ksort($comparable);
            ksort($started);
            // Stored records are canonical (sorted keys); compare content, not key order.
            if ($started !== $comparable) {
                $record['phase'] = 'draft_changed';
                $record['started'] = $started;
            }
            self::write($path, $record);
            echo json_encode(self::projection($this->rootPath, $contract), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 0;
        } catch (Throwable $exception) {
            echo json_encode(['status' => 'error', 'message' => $exception->getMessage()], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

            return 1;
        }
    }

    /**
     * The current preflight evidence for the Contract as the approval card shows
     * it. Only a record whose every bound identity still matches is reported as
     * validation_passed or validation_failed; everything else is not_run,
     * unknown or stale, never a clean result.
     *
     * @return array{state: string, authority: string, provenance: string|null, reason: string|null, stale_identities: list<string>, observed_out_of_scope_change: list<array{path: string, status: string}>, proposal: string|null, recorded_by: string|null, recorded_at: string|null, identity: array<string, mixed>|null}
     */
    public static function projection(string $rootPath, TaskContract $contract): array
    {
        $result = [
            'state' => 'not_run', 'authority' => 'advisory_only', 'provenance' => null, 'reason' => null, 'stale_identities' => [],
            'observed_out_of_scope_change' => [], 'proposal' => null, 'recorded_by' => null, 'recorded_at' => null, 'identity' => null,
        ];
        $path = self::path($contract);
        if (!is_file($path)) {
            return $result;
        }
        try {
            $record = self::read($path);
        } catch (Throwable) {
            return ['state' => 'unknown', 'reason' => 'unreadable_record'] + $result;
        }
        if (($record['phase'] ?? null) === 'started') {
            return ['reason' => 'started_not_recorded'] + $result;
        }
        $identity = $record['identity'] ?? null;
        $drift = $record['drift'] ?? null;
        if (!is_array($identity) || !is_array($drift) || !is_string($record['workspace'] ?? null)) {
            return ['state' => 'unknown', 'reason' => 'unreadable_record'] + $result;
        }
        $result['provenance'] = 'host_reported';
        $result['recorded_by'] = is_string($record['recorded_by'] ?? null) ? $record['recorded_by'] : null;
        $result['recorded_at'] = is_string($record['recorded_at'] ?? null) ? $record['recorded_at'] : null;
        $result['identity'] = $identity;
        if (($record['phase'] ?? null) === 'draft_changed') {
            return ['state' => 'unknown', 'reason' => 'draft_changed_during_validation'] + $result;
        }
        // Contract, base and validation come from the Contract alone and are
        // compared first, so a changed base reads as stale, not as an
        // unavailable draft; only then is the draft itself observed.
        $declared = self::declaredIdentity($contract);
        $stale = array_values(array_filter(
            ['contract', 'base', 'validation'],
            static fn (string $key): bool => ($identity[$key] ?? null) !== $declared[$key],
        ));
        if ($stale === []) {
            try {
                if (($identity['draft'] ?? null) !== self::identity($contract, $record['workspace'])['draft']) {
                    $stale[] = 'draft';
                }
            } catch (Throwable) {
                return ['state' => 'unknown', 'reason' => 'draft_unavailable'] + $result;
            }
        }
        if ($stale !== []) {
            return ['state' => 'stale', 'stale_identities' => $stale] + $result;
        }
        /** @var list<array{path: string, status: string}> $drift */
        $validation = $record['validation'] ?? null;
        if (!in_array($validation, ['passed', 'failed'], true)) {
            return ['state' => 'unknown', 'reason' => 'unreadable_record'] + $result;
        }
        $result['state'] = $validation === 'passed' ? 'validation_passed' : 'validation_failed';
        $result['observed_out_of_scope_change'] = $drift;
        if ($drift !== []) {
            $result['proposal'] = 'agent-loop workflow plan ' . $contract->taskId . ' --by <actor> --supersede'
                . implode('', array_map(static fn (array $change): string => ' --add-scope ' . $change['path'], $drift));
        }

        return $result;
    }

    /** @return array{contract: string, base: string, draft: string, validation: string} */
    /** @return array{contract: string, base: string, validation: string} */
    private static function declaredIdentity(TaskContract $contract): array
    {
        return [
            'contract' => 'sha256:' . hash('sha256', CanonicalJson::pretty([
                'revision' => $contract->revision, 'goal' => $contract->goal, 'scope' => $contract->scope,
                'non_goals' => $contract->nonGoals, 'acceptance' => $contract->acceptanceCriteria,
            ])),
            'base' => (string) $contract->baseCommit,
            'validation' => 'sha256:' . hash('sha256', CanonicalJson::pretty(['validation' => $contract->validation])),
        ];
    }

    /** @return array{contract: string, base: string, draft: string, validation: string, workspace: string} */
    private static function identity(TaskContract $contract, string $workspace): array
    {
        $base = $contract->baseCommit;
        if ($base === null || preg_match('/^[0-9a-f]{40,64}$/D', $base) !== 1) {
            throw new RuntimeException('Preflight requires a Contract with an exact base commit.');
        }
        $real = realpath($workspace);
        if ($real === false || !is_dir($real)) {
            throw new RuntimeException('Draft workspace does not exist: ' . $workspace);
        }
        $head = self::git($real, ['rev-parse', '--verify', 'HEAD']);
        if ($head !== $base) {
            throw new RuntimeException('Draft workspace HEAD ' . $head . ' is not the Contract base ' . $base . '.');
        }

        return self::declaredIdentity($contract) + [
            'draft' => (new CandidateWorkspaceIdentity())->observe($real, $base, $real . '/.agent-loop'),
            'workspace' => $real,
        ];
    }

    /** @return list<array{path: string, status: string}> */
    private static function drift(TaskContract $contract, string $draft): array
    {
        $tree = substr($draft, strrpos($draft, ':') + 1);
        $output = self::git(dirname(self::path($contract)), ['diff', '--no-ext-diff', '--no-renames', '--name-status', (string) $contract->baseCommit, $tree]);
        $changes = [];
        foreach (array_filter(explode("\n", $output)) as $line) {
            [$status, $path] = explode("\t", $line, 2) + [1 => ''];
            $inScope = false;
            foreach ($contract->scope as $entry) {
                $entry = rtrim($entry, '/');
                if ($path === $entry || str_starts_with($path, $entry . '/')) {
                    $inScope = true;
                    break;
                }
            }
            if (!$inScope) {
                $changes[] = ['path' => $path, 'status' => $status];
            }
        }

        return $changes;
    }

    /** @param list<string> $arguments */
    private static function git(string $directory, array $arguments): string
    {
        $process = proc_open(['git', '-C', $directory, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to run git.');
        }
        $output = trim((string) stream_get_contents($pipes[1]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException('git ' . implode(' ', $arguments) . ' failed in ' . $directory . '.');
        }

        return $output;
    }

    private static function path(TaskContract $contract): string
    {
        return dirname($contract->path) . '/preflight.json';
    }

    /** @return array<string, mixed> */
    private static function read(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unreadable preflight record.');
        }
        $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || ($decoded['schema_version'] ?? null) !== '1.0'
            || ($decoded['kind'] ?? null) !== 'scope_preflight') {
            throw new RuntimeException('Unsupported preflight record schema.');
        }

        return $decoded;
    }

    /** @param array<string, mixed> $data */
    private static function write(string $path, array $data): void
    {
        $data = ['schema_version' => '1.0', 'kind' => 'scope_preflight'] + $data;
        if (file_put_contents($path . '.tmp', CanonicalJson::pretty($data)) === false || !rename($path . '.tmp', $path)) {
            throw new RuntimeException('Unable to write preflight record.');
        }
    }

    /**
     * @param list<string> $tokens
     * @return array<string, string>
     */
    private static function options(array $tokens): array
    {
        $options = [];
        for ($i = 0, $count = count($tokens); $i < $count; ++$i) {
            if (!in_array($tokens[$i], ['--workspace', '--validation', '--by'], true) || isset($options[$tokens[$i]])) {
                throw new InvalidArgumentException('Unknown or duplicate option: ' . $tokens[$i]);
            }
            $options[$tokens[$i]] = $tokens[++$i] ?? '';
        }

        return $options;
    }
}
