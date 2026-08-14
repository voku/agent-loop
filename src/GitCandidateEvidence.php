<?php

declare(strict_types=1);

namespace voku\AgentLoop;

use RuntimeException;

/**
 * Proves that one exact candidate commit became reachable from an exact target.
 *
 * Branch names and historical pull-request state are intentionally absent from
 * the proof. A mutable target ref is resolved once to a commit SHA, then every
 * decision is made against immutable Git objects. Squash merges are accepted
 * only when the integrated commit has exactly the candidate tree.
 */
final readonly class GitCandidateEvidence
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @return array{
     *     status: 'ok',
     *     candidate_sha: non-empty-string,
     *     candidate_tree: non-empty-string,
     *     integration_kind: 'ancestor'|'exact_tree',
     *     integrated_sha: non-empty-string,
     *     integrated_tree: non-empty-string,
     *     target_ref: non-empty-string,
     *     target_sha: non-empty-string,
     *     release_tag: non-empty-string|null,
     *     tag_object_sha: non-empty-string|null,
     *     release_commit_sha: non-empty-string|null
     * }
     */
    public function prove(
        string $candidate,
        string $integrated,
        string $targetRef,
        ?string $releaseTag = null,
    ): array {
        $candidate = $this->exactObjectId($candidate, 'candidate SHA');
        $integrated = $this->exactObjectId($integrated, 'integrated SHA');
        $targetRef = $this->nonEmpty($targetRef, 'target ref');

        $candidateSha = $this->commit($candidate, 'candidate');
        $integratedSha = $this->commit($integrated, 'integrated');
        $targetSha = $this->commit($targetRef, 'target');
        $candidateTree = $this->tree($candidateSha);
        $integratedTree = $this->tree($integratedSha);

        if ($this->isAncestor($candidateSha, $integratedSha)) {
            $integrationKind = 'ancestor';
        } elseif (hash_equals($candidateTree, $integratedTree)) {
            $integrationKind = 'exact_tree';
        } else {
            throw new RuntimeException(sprintf(
                'Candidate %s is neither an ancestor of integrated commit %s nor exact-tree-equivalent to it.',
                $candidateSha,
                $integratedSha,
            ));
        }

        if (!$this->isAncestor($integratedSha, $targetSha)) {
            throw new RuntimeException(sprintf(
                'Integrated commit %s is not reachable from frozen target %s (%s).',
                $integratedSha,
                $targetSha,
                $targetRef,
            ));
        }

        $tagObjectSha = null;
        $releaseCommitSha = null;
        if ($releaseTag !== null) {
            $releaseTag = $this->nonEmpty($releaseTag, 'release tag');
            $this->assertTagName($releaseTag);
            $tagRef = 'refs/tags/' . $releaseTag;
            $tagObjectSha = $this->mustGit(
                ['rev-parse', '--verify', '--end-of-options', $tagRef],
                'release tag object',
            );
            $releaseCommitSha = $this->commit($tagObjectSha, 'release tag object');
            if (!$this->isAncestor($integratedSha, $releaseCommitSha)) {
                throw new RuntimeException(sprintf(
                    'Release tag %s resolves to %s, which does not contain integrated commit %s.',
                    $releaseTag,
                    $releaseCommitSha,
                    $integratedSha,
                ));
            }
        }

        return [
            'status' => 'ok',
            'candidate_sha' => $candidateSha,
            'candidate_tree' => $candidateTree,
            'integration_kind' => $integrationKind,
            'integrated_sha' => $integratedSha,
            'integrated_tree' => $integratedTree,
            'target_ref' => $targetRef,
            'target_sha' => $targetSha,
            'release_tag' => $releaseTag,
            'tag_object_sha' => $tagObjectSha,
            'release_commit_sha' => $releaseCommitSha,
        ];
    }

    /** @return non-empty-string */
    private function commit(string $ref, string $label): string
    {
        return $this->mustGit(
            ['rev-parse', '--verify', '--end-of-options', $ref . '^{commit}'],
            $label . ' commit',
        );
    }

    /** @return non-empty-string */
    private function tree(string $commit): string
    {
        return $this->mustGit(
            ['rev-parse', '--verify', '--end-of-options', $commit . '^{tree}'],
            'commit tree',
        );
    }

    private function isAncestor(string $ancestor, string $descendant): bool
    {
        $result = $this->git(['merge-base', '--is-ancestor', $ancestor, $descendant]);
        if ($result['exit'] === 0) {
            return true;
        }
        if ($result['exit'] === 1) {
            return false;
        }

        throw new RuntimeException(sprintf(
            'Git could not compare candidate ancestry (exit %d): %s',
            $result['exit'],
            trim($result['stderr']),
        ));
    }

    private function assertTagName(string $tag): void
    {
        $result = $this->git(['check-ref-format', 'refs/tags/' . $tag]);
        if ($result['exit'] !== 0) {
            throw new RuntimeException('Invalid release tag: ' . $tag);
        }
    }

    /**
     * @param non-empty-list<string> $arguments
     * @return non-empty-string
     */
    private function mustGit(array $arguments, string $label): string
    {
        $result = $this->git($arguments);
        $stdout = trim($result['stdout']);
        if ($result['exit'] !== 0 || $stdout === '') {
            throw new RuntimeException(sprintf(
                'Unable to resolve %s (git exit %d): %s',
                $label,
                $result['exit'],
                trim($result['stderr']),
            ));
        }

        return $stdout;
    }

    /**
     * @param non-empty-list<string> $arguments
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function git(array $arguments): array
    {
        if (!is_dir($this->rootPath)) {
            throw new RuntimeException('Git candidate evidence root is not a directory: ' . $this->rootPath);
        }

        $process = proc_open(
            ['git', ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->rootPath,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start Git for candidate evidence.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => is_string($stdout) ? $stdout : '',
            'stderr' => is_string($stderr) ? $stderr : '',
        ];
    }

    /** @return non-empty-string */
    private function exactObjectId(string $value, string $label): string
    {
        $value = $this->nonEmpty($value, $label);
        if (preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/i', $value) !== 1) {
            throw new RuntimeException($label . ' must be a full Git object ID, not a branch, tag, HEAD, or abbreviated SHA.');
        }

        return $value;
    }

    /** @return non-empty-string */
    private function nonEmpty(string $value, string $label): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new RuntimeException('Missing ' . $label . '.');
        }

        return $value;
    }
}
