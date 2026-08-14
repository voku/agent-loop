<?php

declare(strict_types=1);

namespace voku\AgentLoop\Edit\Verify;

use RuntimeException;
use voku\AgentMap\Index\IndexReader;

/**
 * Executes the objective gates a plan declares.
 *
 * Two kinds shell out (`approved_validation_command`, `agent_loop_verify`). Running a command that
 * arrived inside a JSON file, silently, as a side effect of "verify", is not something this should
 * do by default - so those report `not_run` unless the caller passes `--run-commands`, and a
 * required gate that did not run counts as a failure, never as a pass. Refusing to run is allowed;
 * pretending it passed is not.
 *
 * The post-edit map is refreshed into the bundle's own index, never the shared one: grading must
 * not mutate state the next task will read.
 */
final readonly class ObjectiveGateRunner
{
    public function __construct(
        private MapRefresher $mapRefresher = new MapRefresher(),
        private CommandRunner $commandRunner = new CommandRunner(),
    ) {
    }

    /**
     * @return list<array{id: string, kind: string, required: bool, status: string, detail: string}>
     */
    public function run(VerificationBundle $bundle, string $projectRoot, bool $runCommands): array
    {
        $postEditMap = null;
        $results = [];
        foreach ($bundle->objectiveGates() as $gate) {
            $kind = is_string($gate['kind'] ?? null) ? $gate['kind'] : 'unknown';
            $id = is_string($gate['id'] ?? null) ? $gate['id'] : $kind;
            $required = ($gate['required'] ?? true) !== false;
            /** @var array<string, mixed> $provenance */
            $provenance = is_array($gate['provenance'] ?? null) ? $gate['provenance'] : [];

            if (in_array($kind, ['post_edit_map_fresh', 'target_resolvable'], true) && $postEditMap === null) {
                $postEditMap = $this->mapRefresher->refresh($bundle, $projectRoot);
            }

            $outcome = match ($kind) {
                'runner_exit' => $this->runnerExit($bundle),
                'php_lint_changed_files' => $this->lintChangedFiles($bundle, $projectRoot),
                'post_edit_map_fresh' => $this->mapFresh($postEditMap),
                'target_resolvable' => $this->targetResolvable($postEditMap, $bundle->target),
                'approved_validation_command', 'agent_loop_verify' => $this->command($provenance, $projectRoot, $runCommands),
                default => ['status' => 'not_run', 'detail' => 'Unknown gate kind; agent-loop refuses to grade what it cannot execute.'],
            };

            $results[] = [
                'id' => $id,
                'kind' => $kind,
                'required' => $required,
                'status' => $outcome['status'],
                'detail' => $outcome['detail'],
            ];
        }

        return $results;
    }

    /** @return array{status: string, detail: string} */
    private function runnerExit(VerificationBundle $bundle): array
    {
        $runner = $bundle->execution['runner'] ?? null;
        $exitCode = is_array($runner) ? ($runner['exit_code'] ?? null) : null;
        $status = $bundle->execution['status'] ?? null;

        if ($status === 'prepared' && $exitCode === null) {
            return ['status' => 'not_run', 'detail' => 'The edit was only prepared; no runner executed.'];
        }
        if ($exitCode === 0) {
            return ['status' => 'passed', 'detail' => 'Runner exited 0.'];
        }

        return ['status' => 'failed', 'detail' => 'Runner exit code: ' . var_export($exitCode, true) . ', status: ' . var_export($status, true) . '.'];
    }

    /** @return array{status: string, detail: string} */
    private function lintChangedFiles(VerificationBundle $bundle, string $projectRoot): array
    {
        if (!$bundle->changedFilesObserved()) {
            return ['status' => 'not_run', 'detail' => 'The edit recorded no observed working-tree diff, so there is no verified file list to lint.'];
        }

        $failures = [];
        $linted = 0;
        foreach ($bundle->changedFiles() as $file) {
            if (!str_ends_with(strtolower($file), '.php')) {
                continue;
            }
            $absolute = $projectRoot . '/' . $file;
            if (!is_file($absolute)) {
                // A deleted file is a legitimate outcome of an edit and nothing to lint.
                continue;
            }

            ++$linted;
            $result = $this->commandRunner->run([PHP_BINARY, '-n', '-l', $absolute], $projectRoot);
            if ($result['exit_code'] !== 0) {
                $failures[] = $file;
            }
        }

        if ($failures !== []) {
            return ['status' => 'failed', 'detail' => 'PHP lint failed for: ' . implode(', ', $failures) . '.'];
        }

        return ['status' => 'passed', 'detail' => $linted === 0 ? 'No changed PHP files to lint.' : $linted . ' changed PHP file(s) linted.'];
    }

    /**
     * @param array{available: bool, index: ?string, stale: list<string>, detail: string}|null $map
     *
     * @return array{status: string, detail: string}
     */
    private function mapFresh(?array $map): array
    {
        if ($map === null || !$map['available']) {
            return ['status' => 'not_run', 'detail' => $map['detail'] ?? 'No post-edit map was produced.'];
        }
        if ($map['stale'] !== []) {
            return ['status' => 'failed', 'detail' => count($map['stale']) . ' indexed file(s) no longer match the working tree, starting with ' . $map['stale'][0] . '.'];
        }

        return ['status' => 'passed', 'detail' => 'Post-edit map matches the working tree.'];
    }

    /**
     * @param array{available: bool, index: ?string, stale: list<string>, detail: string}|null $map
     *
     * @return array{status: string, detail: string}
     */
    private function targetResolvable(?array $map, string $target): array
    {
        if ($map === null || !$map['available'] || $map['index'] === null) {
            return ['status' => 'not_run', 'detail' => $map['detail'] ?? 'No post-edit map was produced.'];
        }

        try {
            (new IndexReader())->read($map['index'])->resolveMethod($target);
        } catch (RuntimeException $exception) {
            return ['status' => 'failed', 'detail' => 'Target is no longer resolvable after the edit: ' . $exception->getMessage()];
        }

        return ['status' => 'passed', 'detail' => 'Target still resolves in the post-edit map.'];
    }

    /**
     * @param array<string, mixed> $provenance
     *
     * @return array{status: string, detail: string}
     */
    private function command(array $provenance, string $projectRoot, bool $runCommands): array
    {
        $command = $provenance['command'] ?? null;
        if (!is_string($command) || trim($command) === '') {
            return ['status' => 'not_run', 'detail' => 'The gate declares no command.'];
        }
        if (!$runCommands) {
            return ['status' => 'not_run', 'detail' => 'Declared command not executed without --run-commands: ' . $command];
        }

        $result = $this->commandRunner->runShell($command, $projectRoot);

        return $result['exit_code'] === 0
            ? ['status' => 'passed', 'detail' => $command . ' exited 0.']
            : ['status' => 'failed', 'detail' => $command . ' exited ' . var_export($result['exit_code'], true) . '.'];
    }
}
