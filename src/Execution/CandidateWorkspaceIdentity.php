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
    public function assertHolds(string $workspace, ?string $baseCommit, string $candidateRevision, ?string $excludedStatePath = null): void
    {
        if ($baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('STALE_CANDIDATE: verify requires an exact governed base commit.');
        }

        $observedTree = $this->tree($workspace, $baseCommit, $excludedStatePath);
        $expectedTree = hash_equals($baseCommit, $candidateRevision)
            ? $this->git($workspace, ['rev-parse', '--verify', $baseCommit . '^{tree}'])
            : $this->candidateTree($baseCommit, $candidateRevision);

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

    private function tree(string $workspace, string $baseCommit, ?string $excludedStatePath): string
    {
        $head = $this->git($workspace, ['rev-parse', '--verify', 'HEAD']);
        if (!hash_equals($baseCommit, $head)) {
            throw new RuntimeException(sprintf('STALE_CANDIDATE: verify workspace HEAD is %s, not the governed base %s.', $head, $baseCommit));
        }

        $index = tempnam(sys_get_temp_dir(), 'agent-loop-verify-index-');
        if (!is_string($index) || !unlink($index)) {
            throw new RuntimeException('Unable to prepare a private Git index for candidate observation.');
        }
        $environment = ['GIT_INDEX_FILE' => $index];
        try {
            $this->git($workspace, ['read-tree', $baseCommit], $environment);
            $this->git($workspace, ['add', '-A', '--', '.'], $environment);
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
