<?php

declare(strict_types=1);

use voku\AgentLoop\Execution\CandidateWorkspaceIdentity;
use voku\AgentLoop\Execution\ExecutionEvidenceKind;
use voku\AgentLoop\Execution\ExecutionEvidenceStore;
use voku\AgentLoop\Execution\ExecutionGateway;
use voku\AgentLoop\Execution\ExecutionPlanStore;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\ExecutionStateStore;
use voku\AgentLoop\Execution\StageCandidateObservation;
use voku\AgentLoop\Execution\StageResult;
use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowPipelineCommand;
use voku\AgentLoop\Workflow\WorkflowStatusCommand;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        $arguments = parseArguments($argv ?? []);
        $result = (new PhpRuntimeAuditPra2RecoveryReplay())->run($arguments);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
    } catch (Throwable $exception) {
        fwrite(STDERR, '[FAIL] PRA-2 recovery replay: ' . $exception->getMessage() . "\n");
        exit(1);
    }
}

/**
 * @param list<string> $argv
 * @return array{source: string, task: string, expected_snapshot: string, accepted_candidate: string}
 */
function parseArguments(array $argv): array
{
    $options = [];
    for ($index = 1, $length = count($argv); $index < $length; ++$index) {
        $name = $argv[$index];
        if (!str_starts_with($name, '--') || !isset($argv[$index + 1])) {
            throw new RuntimeException('Expected --source, --task, --expected-snapshot, and --accepted-candidate options.');
        }
        $value = $argv[++$index];
        if ($value === '' || isset($options[$name])) {
            throw new RuntimeException('Dogfood option is empty or repeated: ' . $name);
        }
        $options[$name] = $value;
    }

    foreach (['--source', '--task', '--expected-snapshot', '--accepted-candidate'] as $name) {
        if (!isset($options[$name])) {
            throw new RuntimeException('Missing required dogfood option ' . $name . '.');
        }
    }

    return [
        'source' => $options['--source'],
        'task' => $options['--task'],
        'expected_snapshot' => $options['--expected-snapshot'],
        'accepted_candidate' => $options['--accepted-candidate'],
    ];
}

final class PhpRuntimeAuditPra2RecoveryReplay
{
    /**
     * @param array{source: string, task: string, expected_snapshot: string, accepted_candidate: string} $arguments
     * @return array<string, mixed>
     */
    public function run(array $arguments): array
    {
        $task = $arguments['task'];
        $expected = $arguments['expected_snapshot'];
        $acceptedCandidate = $arguments['accepted_candidate'];
        [$source, $contract, $state, $acceptedTree] = self::checkSource($arguments);
        $baseCommit = (string) $contract->baseCommit;

        $sourceFingerprintBefore = self::fingerprint($source);
        $sourcePra4Before = self::fingerprint($source . '/.agent-loop/runs/PRA-4');
        $temporaryRoot = sys_get_temp_dir() . '/agent-loop-pra2-recovery-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryRoot, 0o700)) {
            throw new RuntimeException('Unable to create the isolated replay directory.');
        }

        // The copied project must never inherit an operator allowlist that
        // can route its writes back into the source checkout or shared state.
        $trustedExternalStatePaths = getenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
        putenv('AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS');
        try {
            $project = $temporaryRoot . '/project';
            $isolatedSnapshot = self::materialize($source, $project, $contract, $acceptedTree, $task, $expected);

            $candidate = self::observeCandidate($project, $baseCommit);
            if (hash_equals($acceptedCandidate, $candidate)) {
                throw new RuntimeException('Expected a changed PRA-2 candidate, but the isolated candidate is unchanged.');
            }
            (new CandidateWorkspaceIdentity())->assertHolds(
                $project,
                $baseCommit,
                $candidate,
                self::relativeStateRoot($project),
            );

            $beforeHistory = count($state->history);
            $expectedBuildAttempt = self::nextBuildAttempt($state);
            $pipeline = new WorkflowPipelineCommand($project);
            $afterRecovery = self::assertRecovered(
                $pipeline,
                $project,
                $task,
                $acceptedCandidate,
                $candidate,
                $expectedBuildAttempt,
                $beforeHistory,
            );
            self::assertIdempotent($pipeline, $project, $task, $candidate);
            $candidateEvidence = self::bindCandidateEvidence($project, $task, $acceptedCandidate, $candidate);
            self::assertStaleReviewRejected($project, $afterRecovery);

            $finalState = (new ExecutionStateStore($project))->find($task);
            $pra4After = self::fingerprint($project . '/.agent-loop/runs/PRA-4');
            $sourceFingerprintAfter = self::fingerprint($source);
            if ($pra4After !== $sourcePra4Before) {
                throw new RuntimeException('PRA-4 evidence changed in the isolated PRA-2 replay.');
            }
            if ($sourceFingerprintAfter !== $sourceFingerprintBefore) {
                throw new RuntimeException('The source php-runtime-audit worktree changed during the isolated replay.');
            }
            if ($finalState === null
                || $finalState->currentStageId !== 'build'
                || $finalState->currentAttempt !== $expectedBuildAttempt
                || count($finalState->history) !== $beforeHistory) {
                throw new RuntimeException('Replay altered the active build stage or accepted historical results.');
            }

            return [
                'status' => 'passed',
                'task_id' => $task,
                'contract_revision' => $contract->revision,
                'run_id' => $state->runId,
                'accepted_candidate' => $acceptedCandidate,
                'recovered_candidate' => $candidate,
                'implementation_snapshot' => $isolatedSnapshot,
                'recovered_changed_files' => $afterRecovery->recoveryHistory[0]['changed_files'],
                'current_stage' => $finalState->currentStageId,
                'attempt' => $expectedBuildAttempt,
                'prior_history_count' => $beforeHistory,
                'recovery_event_count' => count($finalState->recoveryHistory),
                'candidate_observation_reference' => $candidateEvidence,
                'historical_review_replay_rejected' => true,
                'pra4_evidence_unchanged' => true,
                'source_worktree_unchanged' => true,
            ];
        } finally {
            try {
                self::removeTree($temporaryRoot);
            } finally {
                putenv($trustedExternalStatePaths === false
                    ? 'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS'
                    : 'AGENT_LOOP_TRUSTED_EXTERNAL_STATE_PATHS=' . $trustedExternalStatePaths);
            }
        }
    }

    /**
     * @param array{source: string, task: string, expected_snapshot: string, accepted_candidate: string} $arguments
     * @return array{string, TaskContract, ExecutionState, string} source, Contract, completed state, accepted tree id
     */
    private static function checkSource(array $arguments): array
    {
        $source = realpath($arguments['source']);
        if (!is_string($source) || !is_dir($source) || !GitWorkTree::detected($source)) {
            throw new RuntimeException('Source must be a Git worktree.');
        }
        if (is_file($source . '/.git/objects/info/alternates')) {
            throw new RuntimeException('Source Git object alternates are unsupported by this isolated replay.');
        }

        $contract = (new TaskContractStore($source))->load($arguments['task']);
        if ($contract->status !== TaskContract::APPROVED || $contract->baseCommit === null) {
            throw new RuntimeException('PRA-2 replay requires the current approved Contract with an exact Git base.');
        }
        $expected = $arguments['expected_snapshot'];
        if (preg_match('/^sha256:[a-f0-9]{64}$/', $expected) !== 1) {
            throw new RuntimeException('Expected implementation snapshot must be a sha256 digest.');
        }
        $sourceSnapshot = ImplementationSnapshot::capture($source, $contract)->digest;
        if (!hash_equals($expected, $sourceSnapshot)) {
            throw new RuntimeException('Source implementation snapshot differs from the supplied expected snapshot.');
        }

        $acceptedCandidate = $arguments['accepted_candidate'];
        if (preg_match(
            '/^git-tree-v1:' . preg_quote($contract->baseCommit, '/') . ':([0-9a-f]{40}|[0-9a-f]{64})$/',
            $acceptedCandidate,
            $acceptedMatch,
        ) !== 1) {
            throw new RuntimeException('Accepted candidate is not bound to the approved Contract base.');
        }
        $plan = (new ExecutionPlanStore($source))->load($arguments['task']);
        $state = (new ExecutionStateStore($source))->find($arguments['task']);
        if (!$state instanceof ExecutionState
            || $state->currentStageId !== null
            || $state->attention !== null
            || !hash_equals($acceptedCandidate, $state->candidateRevision)
            || $plan->baseCommit === null
            || !hash_equals($contract->baseCommit, $plan->baseCommit)) {
            throw new RuntimeException('Source PRA-2 execution state is not the completed accepted candidate supplied for replay.');
        }

        return [$source, $contract, $state, $acceptedMatch[1]];
    }

    /** Builds the disposable project and returns the reproduced implementation snapshot digest. */
    private static function materialize(
        string $source,
        string $project,
        TaskContract $contract,
        string $acceptedTree,
        string $task,
        string $expected,
    ): string {
        self::git($source, ['clone', '--quiet', '--no-hardlinks', '--no-checkout', $source, $project]);
        self::git($project, ['checkout', '--quiet', '--detach', (string) $contract->baseCommit]);
        self::copyTree($source . '/.git/objects', $project . '/.git/objects');
        if (self::git($project, ['cat-file', '-t', $acceptedTree]) !== 'tree') {
            throw new RuntimeException('Accepted PRA-2 candidate tree is not available in the isolated Git object store.');
        }

        // Recreate the accepted A tree in the disposable worktree. The
        // owner state and current scoped files are overlaid only after A is
        // materialized, so unrelated current PRA-4 files never join B.
        self::git($project, ['read-tree', '--reset', '-u', $acceptedTree]);
        self::removeTree($project . '/.agent-loop');
        self::copyTree($source . '/.agent-loop', $project . '/.agent-loop');
        foreach ($contract->scope as $scopePath) {
            $relative = self::canonicalScopePath($scopePath);
            self::assertNoSymlinkComponents($source, $relative);
            self::assertNoSymlinkComponents($project, $relative);
            $sourcePath = $source . '/' . $relative;
            $targetPath = $project . '/' . $relative;
            self::removeTree($targetPath);
            if (file_exists($sourcePath) || is_link($sourcePath)) {
                if (is_link($sourcePath)) {
                    throw new RuntimeException('Approved PRA-2 scope contains a symlink: ' . $relative);
                }
                self::copyTree($sourcePath, $targetPath);
            }
        }

        $isolatedContract = (new TaskContractStore($project))->load($task);
        $isolatedSnapshot = ImplementationSnapshot::capture($project, $isolatedContract)->digest;
        if (!hash_equals($expected, $isolatedSnapshot)) {
            throw new RuntimeException('Isolated approved-scope overlay does not reproduce the expected implementation snapshot.');
        }

        return $isolatedSnapshot;
    }

    /** Recovers the candidate and asserts the new build attempt, preserved history and canonical status routing. */
    private static function assertRecovered(
        WorkflowPipelineCommand $pipeline,
        string $project,
        string $task,
        string $acceptedCandidate,
        string $candidate,
        int $expectedBuildAttempt,
        int $beforeHistory,
    ): ExecutionState {
        [$exit, $payload] = self::captureCommand($pipeline, ['recover', $task, '--candidate', $candidate, '--format=json']);
        if ($exit !== 0 || ($payload->current_stage ?? null) !== 'build' || ($payload->attempt ?? null) !== $expectedBuildAttempt) {
            throw new RuntimeException('Recovery did not reopen PRA-2 at the next build attempt: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }

        $afterRecovery = (new ExecutionStateStore($project))->find($task);
        if (!$afterRecovery instanceof ExecutionState
            || count($afterRecovery->history) !== $beforeHistory
            || count($afterRecovery->recoveryHistory) !== 1
            || !hash_equals($acceptedCandidate, $afterRecovery->recoveryHistory[0]['previous_candidate_revision'])
            || !hash_equals($candidate, $afterRecovery->recoveryHistory[0]['observed_candidate_revision'])) {
            throw new RuntimeException('Recovery did not preserve accepted history or append exactly one candidate-bound recovery event.');
        }

        [$statusExit, $workflowStatus] = self::captureWorkflowStatus(new WorkflowStatusCommand($project), $task);
        if (!in_array($statusExit, [0, 2], true)
            || ($workflowStatus->manifest->references->verification->gate ?? null) !== 'execution_review'
            || !str_starts_with(
                (string) ($workflowStatus->manifest->references->verification->reason ?? ''),
                'recovered execution pipeline:',
            )
            || ($workflowStatus->policy->next_action ?? null) !== 'agent-loop pipeline status ' . $task . ' --format=json') {
            throw new RuntimeException('Canonical workflow status did not route the recovered, incomplete pipeline to its current stage.');
        }

        return $afterRecovery;
    }

    private static function assertIdempotent(WorkflowPipelineCommand $pipeline, string $project, string $task, string $candidate): void
    {
        [$retryExit, $retryPayload] = self::captureCommand($pipeline, ['recover', $task, '--candidate', $candidate, '--format=json']);
        $retryState = (new ExecutionStateStore($project))->find($task);
        if ($retryExit !== 0
            || ($retryPayload->current_stage ?? null) !== 'build'
            || $retryState === null
            || count($retryState->recoveryHistory) !== 1) {
            throw new RuntimeException('Repeating candidate recovery was not idempotent.');
        }
    }

    /** Records the build-stage candidate observation and returns its evidence reference. */
    private static function bindCandidateEvidence(string $project, string $task, string $acceptedCandidate, string $candidate): string
    {
        $gateway = new ExecutionGateway($project);
        $build = $gateway->prepareStage($task, 'build');
        $candidateEvidence = $gateway->recordStageCandidate(new StageCandidateObservation(
            $build->taskId,
            $build->runId,
            $build->contractRevision,
            $build->executionPlanDigest,
            $build->stageId,
            $build->attempt,
            $acceptedCandidate,
            $candidate,
        ));
        (new ExecutionEvidenceStore($project))->assertCurrent(
            $candidateEvidence,
            ExecutionEvidenceKind::CANDIDATE,
            (new ExecutionPlanStore($project))->load($task),
            'build',
            $build->attempt,
            $candidate,
        );

        return $candidateEvidence;
    }

    private static function assertStaleReviewRejected(string $project, ExecutionState $afterRecovery): void
    {
        $oldReview = self::latestCorrectnessReview($afterRecovery);
        try {
            (new ExecutionGateway($project))->submitStageResult($oldReview);
            throw new RuntimeException('A historical review result was accepted after candidate recovery.');
        } catch (RuntimeException $exception) {
            if (!str_contains($exception->getMessage(), 'STALE_STAGE_RESULT')) {
                throw $exception;
            }
        }
    }

    private static function canonicalScopePath(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Approved Contract scope path is invalid: ' . $path);
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('Approved Contract scope path is unsafe: ' . $path);
            }
        }

        return $path;
    }

    public static function assertNoSymlinkComponents(string $root, string $relativePath): void
    {
        $rootPath = realpath($root);
        if (!is_string($rootPath) || !is_dir($rootPath)) {
            throw new RuntimeException('Isolated replay path root cannot be resolved.');
        }
        $relative = self::canonicalScopePath($relativePath);
        $current = $rootPath;
        foreach (explode('/', $relative) as $segment) {
            $current .= DIRECTORY_SEPARATOR . $segment;
            if (!file_exists($current) && !is_link($current)) {
                break;
            }
            $stat = lstat($current);
            if (!is_array($stat)) {
                throw new RuntimeException('Unable to inspect isolated replay path component: ' . $relative);
            }
            if (is_link($current)) {
                throw new RuntimeException('Symbolic link component blocks isolated replay path: ' . $relative);
            }
        }
    }

    private static function observeCandidate(string $root, string $baseCommit): string
    {
        $index = tempnam(sys_get_temp_dir(), 'agent-loop-pra2-candidate-index-');
        if (!is_string($index) || !unlink($index)) {
            throw new RuntimeException('Unable to prepare private candidate index for the PRA-2 replay.');
        }
        $environment = self::cleanGitEnvironment();
        $environment['GIT_INDEX_FILE'] = $index;
        try {
            self::git($root, ['read-tree', $baseCommit], $environment);
            self::git($root, ['add', '-A', '--', '.'], $environment);
            $stateRoot = self::relativeStateRoot($root);
            if ($stateRoot !== null) {
                self::git($root, ['reset', '-q', $baseCommit, '--', $stateRoot], $environment);
            }
            $tree = self::git($root, ['write-tree'], $environment);
        } finally {
            if (is_file($index)) {
                unlink($index);
            }
        }

        return 'git-tree-v1:' . $baseCommit . ':' . $tree;
    }

    private static function relativeStateRoot(string $root): ?string
    {
        $root = realpath($root);
        if (!is_string($root)) {
            throw new RuntimeException('Unable to resolve the isolated replay root.');
        }
        $state = realpath((new ProjectLayout($root))->stateRoot());
        if (!is_string($state) || !str_starts_with($state, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($state, strlen($root) + 1));
    }

    /**
     * @param list<string> $arguments
     * @return array{int, \stdClass}
     */
    private static function captureCommand(WorkflowPipelineCommand $command, array $arguments): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $command->run($arguments);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $decoded = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('Recovery command returned an invalid JSON payload.');
        }

        return [$exit, $decoded];
    }

    /** @return array{int, \stdClass} */
    private static function captureWorkflowStatus(WorkflowStatusCommand $command, string $taskId): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = $command->run([$taskId, '--format=json']);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }
        $decoded = json_decode($output, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof stdClass) {
            throw new RuntimeException('Workflow status returned an invalid JSON payload.');
        }

        return [$exit, $decoded];
    }

    private static function latestCorrectnessReview(ExecutionState $state): StageResult
    {
        foreach (array_reverse($state->history) as $accepted) {
            if (in_array($accepted->result->stageId, ['correctness-review', 'review'], true)) {
                return $accepted->result;
            }
        }

        throw new RuntimeException('Accepted PRA-2 state contains no historical correctness review result.');
    }

    private static function nextBuildAttempt(ExecutionState $state): int
    {
        $attempt = 1;
        foreach ($state->history as $accepted) {
            if ($accepted->result->stageId === 'build') {
                ++$attempt;
            }
        }

        return $attempt;
    }

    /**
     * @param non-empty-list<string> $arguments
     * @param array<string, string>|null $environment
     */
    private static function git(string $cwd, array $arguments, ?array $environment = null): string
    {
        $pipes = [];
        $process = proc_open(
            ['git', '--no-replace-objects', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            $environment ?? self::cleanGitEnvironment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Git for the isolated replay.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Git ' . $arguments[0] . ' failed: ' . trim(is_string($stderr) ? $stderr : ''));
        }

        return trim($stdout);
    }

    /** @return array<string, string> */
    private static function cleanGitEnvironment(): array
    {
        $environment = getenv();
        foreach ([
            'GIT_DIR',
            'GIT_WORK_TREE',
            'GIT_INDEX_FILE',
            'GIT_COMMON_DIR',
            'GIT_OBJECT_DIRECTORY',
            'GIT_ALTERNATE_OBJECT_DIRECTORIES',
            'GIT_PREFIX',
            'GIT_NAMESPACE',
            'GIT_CEILING_DIRECTORIES',
        ] as $key) {
            unset($environment[$key]);
        }

        return $environment;
    }

    private static function fingerprint(string $directory): string
    {
        if (!is_dir($directory)) {
            throw new RuntimeException('Cannot fingerprint missing directory: ' . $directory);
        }
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($iterator as $item) {
            $path = $item->getPathname();
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
            $stat = lstat($path);
            if (!is_array($stat)) {
                throw new RuntimeException('Unable to fingerprint path: ' . $relative);
            }
            if (is_link($path)) {
                $target = readlink($path);
                if (!is_string($target)) {
                    throw new RuntimeException('Unable to fingerprint symbolic link: ' . $relative);
                }
                $entries[] = ['path' => $relative, 'type' => 'link', 'mode' => $stat['mode'] & 07777, 'target' => $target];
            } elseif (is_file($path)) {
                $digest = hash_file('sha256', $path);
                if (!is_string($digest)) {
                    throw new RuntimeException('Unable to hash file: ' . $relative);
                }
                $entries[] = ['path' => $relative, 'type' => 'file', 'mode' => $stat['mode'] & 07777, 'sha256' => $digest];
            } elseif (is_dir($path)) {
                $entries[] = ['path' => $relative, 'type' => 'directory', 'mode' => $stat['mode'] & 07777];
            } else {
                throw new RuntimeException('Unsupported filesystem object in fingerprint: ' . $relative);
            }
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        return hash('sha256', json_encode($entries, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function copyTree(string $source, string $target): void
    {
        if (is_link($source)) {
            throw new RuntimeException('Symbolic links are not supported in an isolated replay: ' . $source);
        }
        if (is_dir($source)) {
            if (is_file($target) || is_link($target)) {
                self::removeTree($target);
            }
            if (!is_dir($target) && !mkdir($target, fileperms($source) & 0777, true) && !is_dir($target)) {
                throw new RuntimeException('Unable to create replay directory: ' . $target);
            }
            $iterator = new DirectoryIterator($source);
            foreach ($iterator as $item) {
                if ($item->isDot()) {
                    continue;
                }
                self::copyTree($item->getPathname(), $target . DIRECTORY_SEPARATOR . $item->getFilename());
            }
            chmod($target, fileperms($source) & 0777);

            return;
        }
        if (!is_file($source)) {
            throw new RuntimeException('Unsupported source filesystem object: ' . $source);
        }
        if (is_dir($target) || is_link($target)) {
            self::removeTree($target);
        }
        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0o775, true) && !is_dir($parent)) {
            throw new RuntimeException('Unable to create replay parent directory: ' . $parent);
        }
        if (!copy($source, $target)) {
            throw new RuntimeException('Unable to copy source file into replay: ' . $source);
        }
        chmod($target, fileperms($source) & 0777);
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Unable to remove replay path: ' . $path);
            }

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $iterator = new DirectoryIterator($path);
        foreach ($iterator as $item) {
            if (!$item->isDot()) {
                self::removeTree($item->getPathname());
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('Unable to remove replay directory: ' . $path);
        }
    }
}
