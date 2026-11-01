<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use RuntimeException;

/**
 * Observes which governed candidate a workspace actually holds.
 *
 * The identity is the content-addressed `git-tree-v1:<base>:<tree>` form that execution consumers report for
 * mutating stages: the workspace's files (tracked and untracked, honouring ignore rules) staged into a private
 * index on top of the governed base commit. Loop recomputes it itself, so a consumer-supplied path is evidence
 * only after its content matches the candidate the Run already accepted. Loop's own state root is excluded: it is
 * governance state that Loop itself writes while verifying, not candidate content.
 */
final readonly class CandidateWorkspaceIdentity
{
    /**
     * Observes the `git-tree-v1:<base>:<tree>` candidate the workspace holds on top of the governed base.
     *
     * With a non-empty `$scopePaths` the candidate is the base overlaid with only those paths from the workspace, so
     * files other tasks keep uncommitted in a shared checkout never become part of it.
     *
     * @param list<string> $scopePaths literal repository-relative paths of the approved Contract scope
     */
    public function observe(string $workspace, ?string $baseCommit, ?string $excludedStatePath = null, array $scopePaths = []): string
    {
        if ($baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('STALE_CANDIDATE: observing a candidate requires an exact governed base commit.');
        }

        return 'git-tree-v1:' . $baseCommit . ':' . $this->tree($workspace, $baseCommit, $excludedStatePath, $scopePaths);
    }

    /**
     * @param list<string> $scopePaths when non-empty, the workspace is compared with the candidate on those paths only
     */
    public function assertHolds(string $workspace, ?string $baseCommit, string $candidateRevision, ?string $excludedStatePath = null, array $scopePaths = []): void
    {
        if ($baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('STALE_CANDIDATE: verify requires an exact governed base commit.');
        }

        $observedTree = $this->tree($workspace, $baseCommit, $excludedStatePath, $scopePaths);
        $expectedTree = $this->expectedTree($workspace, $baseCommit, $candidateRevision);

        if (!hash_equals($expectedTree, $observedTree)) {
            throw new RuntimeException(sprintf(
                'CANDIDATE_MISMATCH: verify workspace %s holds git-tree-v1:%s:%s, not the governed candidate %s.',
                $workspace,
                $baseCommit,
                $observedTree,
                $candidateRevision,
            ));
        }
    }

    /**
     * Normalizes approved Contract scope entries the way hand-written Contracts spell them: backslashes become `/`, a
     * leading `./` and trailing `/` are dropped. An entry naming the whole repository (`.`) yields an empty scope, which
     * keeps the exact whole-tree identity. Absolute and escaping entries are kept so that they are refused.
     *
     * @param list<string> $paths
     * @return list<string>
     */
    public static function normalizeScope(array $paths): array
    {
        $normalized = [];
        foreach ($paths as $path) {
            $path = str_replace('\\', '/', trim($path));
            while (str_starts_with($path, './')) {
                $path = substr($path, 2);
            }
            if ($path === '.') {
                return [];
            }
            $path = $path === '/' ? $path : rtrim($path, '/');
            $normalized[] = $path;
        }

        return array_values(array_unique($normalized));
    }

    /**
     * Whether the workspace differs from the governed base on paths outside the approved scope (a shared checkout).
     *
     * @param list<string> $scopePaths
     */
    public function differsOutsideScope(string $workspace, string $baseCommit, ?string $excludedStatePath, array $scopePaths): bool
    {
        return !hash_equals(
            $this->tree($workspace, $baseCommit, $excludedStatePath, $scopePaths),
            $this->tree($workspace, $baseCommit, $excludedStatePath, []),
        );
    }

    /** Writes the files of an accepted candidate into an empty directory, without registering a Git worktree. */
    public function exportCandidate(string $workspace, string $baseCommit, string $candidateRevision, string $destination): void
    {
        $tree = $this->expectedTree($workspace, $baseCommit, $candidateRevision);
        $index = tempnam(sys_get_temp_dir(), 'agent-loop-export-index-');
        if (!is_string($index) || !unlink($index)) {
            throw new RuntimeException('Unable to prepare a private Git index for the candidate export.');
        }
        $environment = ['GIT_INDEX_FILE' => $index];
        try {
            $this->git($workspace, ['read-tree', $tree], $environment);
            $this->git($workspace, ['checkout-index', '-a', '--prefix=' . rtrim($destination, '/') . '/'], $environment);
        } finally {
            if (is_file($index)) {
                unlink($index);
            }
        }
    }

    /** The `git-tree-v1:<base>:<tree>` form of an accepted stage candidate, which is a bare base commit while nothing changed. */
    public function normalize(string $workspace, string $baseCommit, string $candidateRevision): string
    {
        return 'git-tree-v1:' . $baseCommit . ':' . $this->expectedTree($workspace, $baseCommit, $candidateRevision);
    }

    /** Ensure a candidate workspace belongs to the owner's Git repository. */
    public function assertSameRepository(string $ownerWorkspace, string $candidateWorkspace): void
    {
        $candidatePrefix = $this->git($candidateWorkspace, ['rev-parse', '--show-prefix'], [], false);
        $candidateReal = realpath($candidateWorkspace);
        if (!is_string($candidateReal) || ($candidatePrefix !== "\n" && $candidatePrefix !== "\r\n")) {
            throw new RuntimeException('STALE_CANDIDATE: candidate workspace must be the root of its Git worktree.');
        }

        $ownerCommonDir = $this->gitPath($ownerWorkspace, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $candidateCommonDir = $this->gitPath($candidateWorkspace, ['rev-parse', '--path-format=absolute', '--git-common-dir']);
        $ownerReal = realpath($ownerCommonDir);
        $candidateCommonReal = realpath($candidateCommonDir);
        if (!is_string($ownerReal) || !is_string($candidateCommonReal) || !hash_equals($ownerReal, $candidateCommonReal)) {
            throw new RuntimeException('STALE_CANDIDATE: candidate workspace is not a linked worktree of the owner repository.');
        }

        $registered = $this->git($ownerWorkspace, ['worktree', 'list', '--porcelain', '-z'], [], false);
        foreach (explode("\0", $registered) as $entry) {
            if (!str_starts_with($entry, 'worktree ')) {
                continue;
            }

            $registeredRoot = realpath(substr($entry, strlen('worktree ')));
            if (is_string($registeredRoot) && hash_equals($registeredRoot, $candidateReal)) {
                return;
            }
        }

        throw new RuntimeException('STALE_CANDIDATE: candidate workspace is not a registered linked worktree of the owner repository.');
    }

    /**
     * Whether another live, registered checkout shares the owner's Git repository.
     *
     * Registration alone is not evidence of task ownership. Do not read the other worktree or
     * infer which checkout contains the intended implementation.
     */
    public function hasOtherRegisteredWorktree(string $ownerWorkspace): bool
    {
        $ownerRoot = realpath($ownerWorkspace);
        if (!is_string($ownerRoot)) {
            throw new RuntimeException('STALE_CANDIDATE: owner worktree cannot be resolved.');
        }

        $registered = $this->git($ownerWorkspace, ['worktree', 'list', '--porcelain', '-z'], [], false);
        foreach (explode("\0", $registered) as $entry) {
            if (!str_starts_with($entry, 'worktree ')) {
                continue;
            }
            $root = realpath(substr($entry, strlen('worktree ')));
            if (is_string($root) && $root !== $ownerRoot) {
                return true;
            }
        }

        return false;
    }

    private function expectedTree(string $workspace, string $baseCommit, string $candidateRevision): string
    {
        return hash_equals($baseCommit, $candidateRevision)
            ? $this->git($workspace, ['rev-parse', '--verify', $baseCommit . '^{tree}'])
            : $this->candidateTree($baseCommit, $candidateRevision);
    }

    private function candidateTree(string $baseCommit, string $candidateRevision): string
    {
        if (preg_match(
            '/^git-tree-v1:' . preg_quote($baseCommit, '/') . ':([0-9a-f]{40}|[0-9a-f]{64})$/',
            $candidateRevision,
            $matches,
        ) !== 1) {
            throw new RuntimeException('STALE_CANDIDATE: candidate identity is not a content-addressed Git tree bound to the governed base commit.');
        }

        return $matches[1];
    }

    /** @param non-empty-list<string> $arguments */
    private function gitPath(string $workspace, array $arguments): string
    {
        return rtrim($this->git($workspace, $arguments, [], false), "\r\n");
    }

    /** @param list<string> $scopePaths */
    private function tree(string $workspace, string $baseCommit, ?string $excludedStatePath, array $scopePaths = []): string
    {
        $head = $this->git($workspace, ['rev-parse', '--verify', 'HEAD']);
        if (!hash_equals($baseCommit, $head)) {
            throw new RuntimeException(sprintf('STALE_CANDIDATE: verify workspace HEAD is %s, not the governed base %s.', $head, $baseCommit));
        }
        $this->assertSafeScope($scopePaths);

        $index = tempnam(sys_get_temp_dir(), 'agent-loop-verify-index-');
        if (!is_string($index) || !unlink($index)) {
            throw new RuntimeException('Unable to prepare a private Git index for candidate observation.');
        }
        $environment = ['GIT_INDEX_FILE' => $index];
        try {
            $this->git($workspace, ['read-tree', $baseCommit], $environment);
            if ($scopePaths === []) {
                $this->git($workspace, ['add', '-A', '--', '.'], $environment);
            } else {
                $present = $this->presentScopePaths($workspace, $baseCommit, $scopePaths);
                if ($present !== []) {
                    $this->git($workspace, ['--literal-pathspecs', 'add', '-A', '--', ...$present], $environment);
                }
            }
            if ($excludedStatePath !== null) {
                $this->git($workspace, ['reset', '-q', $baseCommit, '--', $excludedStatePath], $environment);
            }

            return $this->git($workspace, ['write-tree'], $environment);
        } finally {
            if (is_file($index)) {
                unlink($index);
            }
        }
    }

    /**
     * Scope entries are literal repository-relative paths (a file or a directory prefix), never Git pathspec magic:
     * `:(exclude)` or glob characters must not be able to hide or redirect an in-scope difference.
     *
     * @param list<string> $scopePaths
     */
    private function assertSafeScope(array $scopePaths): void
    {
        foreach ($scopePaths as $path) {
            if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/')) {
                throw new RuntimeException('STALE_CANDIDATE: verify scope entry is not a repository-relative path: ' . addcslashes($path, "\0..\37"));
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new RuntimeException('STALE_CANDIDATE: verify scope entry is ambiguous or escapes the repository: ' . $path);
                }
            }
        }
    }

    /**
     * Scope entries that name something the candidate can contain: a path of the base, or a tracked or untracked and
     * not ignored path of the workspace. Staging a path that exists nowhere, or one that is only ignored, is a Git
     * error, and it contributes nothing to the candidate.
     *
     * @param list<string> $scopePaths
     * @return list<string>
     */
    private function presentScopePaths(string $workspace, string $baseCommit, array $scopePaths): array
    {
        $present = [];
        foreach ($scopePaths as $path) {
            if ($this->git($workspace, ['--literal-pathspecs', 'ls-tree', '-r', '--name-only', $baseCommit, '--', $path]) !== ''
                || $this->git($workspace, ['--literal-pathspecs', 'ls-files', '--cached', '--others', '--exclude-standard', '--', $path]) !== '') {
                $present[] = $path;
            }
        }

        return $present;
    }

    /**
     * @param non-empty-list<string> $arguments
     * @param array<string, string> $environment
     */
    private function git(string $workspace, array $arguments, array $environment = [], bool $trimOutput = true): string
    {
        $pipes = [];
        $stderr = tmpfile();
        if ($stderr === false) {
            throw new RuntimeException('Unable to inspect the verify workspace with git.');
        }

        $process = proc_open(
            ['git', ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => $stderr],
            $pipes,
            $workspace,
            $this->childEnvironment($environment),
        );
        if (!is_resource($process)) {
            fclose($stderr);
            throw new RuntimeException('Unable to inspect the verify workspace with git.');
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        rewind($stderr);
        $errorOutput = (string) stream_get_contents($stderr);
        fclose($stderr);
        if ($exitCode !== 0) {
            throw new RuntimeException('STALE_CANDIDATE: git ' . $arguments[0] . ' failed in the verify workspace: ' . trim($errorOutput));
        }

        return $trimOutput ? trim($stdout) : $stdout;
    }

    /**
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function childEnvironment(array $overrides): array
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

        return array_merge($environment, $overrides);
    }
}
