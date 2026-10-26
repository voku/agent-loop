<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\Transparency\ApprovedScope;

/** Owner-side recovery for a completed execution whose workspace candidate changed. */
final readonly class CompletedPipelineRecovery
{
    public function __construct(private string $rootPath)
    {
    }

    public function recover(ExecutionPlan $plan, TaskContract $contract, string $observedCandidateRevision, ?string $candidateWorkspace = null): ExecutionProjection
    {
        if ($contract->status !== TaskContract::APPROVED
            || $contract->taskId !== $plan->taskId
            || $contract->revision !== $plan->contractRevision) {
            throw new RuntimeException('RECOVERY_BLOCKED: recovery requires the exact currently approved Contract bound to this execution plan.');
        }
        $root = realpath($this->rootPath);
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('RECOVERY_BLOCKED: repository root cannot be resolved.');
        }
        if ($plan->baseCommit === null) {
            throw new RuntimeException('RECOVERY_BLOCKED: recovery requires the execution plan exact Git base.');
        }

        $stateStore = new ExecutionStateStore($root);
        $state = $stateStore->find($plan->taskId);
        if (!$state instanceof ExecutionState) {
            throw new RuntimeException('RECOVERY_BLOCKED: execution state must already exist before recovery.');
        }

        // A caller-supplied revision is only a claim. Recompute the tree from the
        // actual owner workspace using a private Git index before comparing it.
        $candidateRoot = realpath($candidateWorkspace ?? $root);
        if (!is_string($candidateRoot) || !is_dir($candidateRoot)) {
            throw new RuntimeException('RECOVERY_BLOCKED: candidate workspace cannot be resolved.');
        }
        $identity = new CandidateWorkspaceIdentity();
        $identity->assertSameRepository($root, $candidateRoot);
        $identity->assertHolds(
            $candidateRoot,
            $plan->baseCommit,
            $observedCandidateRevision,
            $this->relativeStateRoot($root),
        );

        if (hash_equals($state->candidateRevision, $observedCandidateRevision)) {
            return $stateStore->projection($plan);
        }

        $changedFiles = $this->candidateChangedFilesWithinApprovedScope(
            $plan,
            $contract,
            $state->candidateRevision,
            $observedCandidateRevision,
            'RECOVERY_BLOCKED',
        );

        return $stateStore->recoverCompleted(
            $plan,
            $state->candidateRevision,
            $observedCandidateRevision,
            $changedFiles,
            $candidateRoot,
        );
    }

    /** @return list<string> */
    public function candidateChangedFilesWithinApprovedScope(
        ExecutionPlan $plan,
        TaskContract $contract,
        string $previousCandidateRevision,
        string $candidateRevision,
        string $errorPrefix = 'CANDIDATE_SCOPE_MISMATCH',
    ): array {
        if ($contract->status !== TaskContract::APPROVED
            || $contract->taskId !== $plan->taskId
            || $contract->revision !== $plan->contractRevision
            || ($contract->baseCommit !== null && $contract->baseCommit !== $plan->baseCommit)) {
            throw new RuntimeException($errorPrefix . ': scope check requires the exact approved Contract bound to this execution plan.');
        }
        if ($plan->baseCommit === null) {
            throw new RuntimeException($errorPrefix . ': scope check requires the execution plan exact Git base.');
        }

        return $this->changedFilesWithinApprovedScope(
            $plan,
            $contract,
            $previousCandidateRevision,
            $candidateRevision,
            $errorPrefix,
        );
    }

    /** @return list<string> */
    private function changedFilesWithinApprovedScope(
        ExecutionPlan $plan,
        TaskContract $contract,
        string $previousCandidateRevision,
        string $candidateRevision,
        string $errorPrefix,
    ): array {
        if (hash_equals($previousCandidateRevision, $candidateRevision)) {
            return [];
        }
        $root = realpath($this->rootPath);
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException($errorPrefix . ': repository root cannot be resolved.');
        }
        $previousTree = $this->treeForRevision($plan, $previousCandidateRevision);
        $candidateTree = $this->treeForRevision($plan, $candidateRevision);
        $changedFiles = $this->changedFiles($root, $previousTree, $candidateTree);
        if ($changedFiles === []) {
            throw new RuntimeException($errorPrefix . ': candidate revisions differ but Git reports no changed files.');
        }

        (new ExecutionStateStore($root))->assertSafeChangedFiles($changedFiles, $errorPrefix);
        foreach ($changedFiles as $path) {
            if ($this->isSymlinkAtCandidateTree($root, $candidateTree, $path)) {
                throw new RuntimeException($errorPrefix . ': changed file is a symlink: ' . $path);
            }
        }
        $partition = ApprovedScope::fromContract($contract)->partition($changedFiles);
        $outside = $partition['outside'];
        $directoryScopeEntries = [];
        foreach ($partition['inside'] as $path) {
            if (!$this->isExactOrInsideApprovedDirectory(
                $root,
                $previousTree,
                $contract->scope,
                $path,
                $directoryScopeEntries,
            )) {
                $outside[] = $path;
            }
        }
        if ($outside !== []) {
            throw new RuntimeException(
                $errorPrefix . ': changed files are outside approved Contract scope: '
                . implode(', ', $outside),
            );
        }
        if ($partition['inside'] === [] || count($partition['inside']) !== count($changedFiles)) {
            throw new RuntimeException($errorPrefix . ': changed file scope could not be established unambiguously.');
        }

        return $partition['inside'];
    }

    /**
     * @param list<string> $scopeEntries
     * @param array<string, bool> $directoryScopeEntries
     */
    private function isExactOrInsideApprovedDirectory(
        string $root,
        string $previousTree,
        array $scopeEntries,
        string $changedPath,
        array &$directoryScopeEntries,
    ): bool {
        foreach ($scopeEntries as $scopeEntry) {
            $scopePath = trim(str_replace('\\', '/', $scopeEntry), '/');
            if ($scopePath === '.' || $changedPath === $scopePath) {
                return true;
            }
            if (!str_starts_with($changedPath, $scopePath . '/')) {
                continue;
            }
            if (!array_key_exists($scopePath, $directoryScopeEntries)) {
                $directoryScopeEntries[$scopePath] = $this->isDirectoryAtCandidateTree($root, $previousTree, $scopePath);
            }
            if ($directoryScopeEntries[$scopePath]) {
                return true;
            }
        }

        return false;
    }

    private function isDirectoryAtCandidateTree(string $root, string $candidateTree, string $scopePath): bool
    {
        $output = $this->gitOutput(
            [
                '--no-replace-objects',
                '--literal-pathspecs',
                'ls-tree',
                '--full-tree',
                '--format=%(objecttype)',
                $candidateTree,
                '--',
                $scopePath,
            ],
            $root,
        );

        return trim($output) === 'tree';
    }

    private function isSymlinkAtCandidateTree(string $root, string $candidateTree, string $path): bool
    {
        $output = $this->gitOutput(
            [
                '--no-replace-objects',
                '--literal-pathspecs',
                'ls-tree',
                '--full-tree',
                '--format=%(objectmode)',
                $candidateTree,
                '--',
                $path,
            ],
            $root,
        );

        return trim($output) === '120000';
    }

    private function treeForRevision(ExecutionPlan $plan, string $revision): string
    {
        if ($plan->baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $plan->baseCommit) !== 1) {
            throw new RuntimeException('RECOVERY_BLOCKED: execution plan base is not an exact Git commit.');
        }
        if (hash_equals($plan->baseCommit, $revision)) {
            return $this->git(['rev-parse', '--verify', $plan->baseCommit . '^{tree}']);
        }
        if (preg_match(
            '/^git-tree-v1:' . preg_quote($plan->baseCommit, '/') . ':([0-9a-f]{40}|[0-9a-f]{64})$/',
            $revision,
            $matches,
        ) !== 1) {
            throw new RuntimeException('RECOVERY_BLOCKED: candidate identity is not a Git tree bound to the exact execution base.');
        }
        if ($this->git(['cat-file', '-t', $matches[1]]) !== 'tree') {
            throw new RuntimeException('RECOVERY_BLOCKED: candidate object is missing or is not a Git tree.');
        }

        return $matches[1];
    }

    /** @return list<string> */
    private function changedFiles(string $root, string $previousTree, string $observedTree): array
    {
        $output = $this->gitOutput([
            '--no-replace-objects',
            'diff',
            '--no-ext-diff',
            '--no-renames',
            '--name-only',
            '-z',
            $previousTree,
            $observedTree,
            '--',
        ], $root);
        if ($output === '') {
            return [];
        }
        if (!str_ends_with($output, "\0")) {
            throw new RuntimeException('RECOVERY_BLOCKED: Git returned an incomplete changed-file list.');
        }
        $paths = explode("\0", $output);
        array_pop($paths);
        if (in_array('', $paths, true)) {
            throw new RuntimeException('RECOVERY_BLOCKED: Git returned an ambiguous changed-file list.');
        }

        return $paths;
    }

    /** @param non-empty-list<string> $arguments */
    private function git(array $arguments): string
    {
        $output = $this->gitOutput(['--no-replace-objects', ...$arguments], realpath($this->rootPath) ?: $this->rootPath);

        return trim($output);
    }

    /**
     * @param non-empty-list<string> $arguments
     */
    private function gitOutput(array $arguments, string $cwd): string
    {
        $pipes = [];
        $process = proc_open(
            ['git', ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $cwd,
            $this->childEnvironment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('RECOVERY_BLOCKED: unable to inspect the current candidate with Git.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($stdout)) {
            throw new RuntimeException(
                'RECOVERY_BLOCKED: git ' . ($arguments[1] ?? $arguments[0]) . ' failed: '
                . trim(is_string($stderr) ? $stderr : ''),
            );
        }

        return $stdout;
    }

    /** @return array<string, string> */
    private function childEnvironment(): array
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

    private function relativeStateRoot(string $root): ?string
    {
        $stateRoot = realpath((new \voku\AgentLoop\ProjectLayout($root))->stateRoot());
        if (!is_string($stateRoot) || !str_starts_with($stateRoot, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($stateRoot, strlen($root) + 1));
    }
}
