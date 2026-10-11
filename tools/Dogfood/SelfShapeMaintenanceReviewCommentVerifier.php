<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

/**
 * Narrow interpretation of a GitHub issue comment as an *explicit claim*
 * about exact-diff guidance maintenance, never as a self-authenticating verdict.
 *
 * The caller MUST obtain both the comment and the collaborator permission
 * independently through the GitHub API. No environment variable, PR-authored
 * JSON, or CI self-shape approver fixture may supply this trusted input.
 */
final readonly class SelfShapeMaintenanceReviewCommentVerifier
{
    /**
     * @param array<string, mixed> $comment A GitHub REST issue-comment object, not a PR-controlled file
     */
    public function fromTrustedGitHubApi(
        array $comment,
        string $collaboratorPermission,
        string $repository,
        int $pullRequestNumber,
    ): ?SelfShapeMaintenanceReviewCommentEvidence {
        if (!in_array($collaboratorPermission, ['write', 'maintain', 'admin'], true)) {
            return null;
        }

        return $this->parseCandidate($comment, $repository, $pullRequestNumber);
    }

    /**
     * Only screens untrusted marker syntax, source and expected head before
     * spending a GitHub permission API call. It grants no review authority.
     *
     * @param array<string, mixed> $comment
     */
    public function isWellFormedCandidate(
        array $comment,
        string $repository,
        int $pullRequestNumber,
        string $expectedHead,
    ): bool {
        $candidate = $this->parseCandidate($comment, $repository, $pullRequestNumber);

        return $candidate !== null && $candidate->claim->headCommit === $expectedHead;
    }

    /**
     * Private parse only. The returned object becomes trusted evidence solely
     * through fromTrustedGitHubApi() after checking real collaborator permission.
     *
     * @param array<string, mixed> $comment
     */
    private function parseCandidate(
        array $comment,
        string $repository,
        int $pullRequestNumber,
    ): ?SelfShapeMaintenanceReviewCommentEvidence {
        if ($pullRequestNumber < 1
            || preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~', $repository) !== 1) {
            return null;
        }

        $user = $comment['user'] ?? null;
        if (!is_array($user) || ($user['type'] ?? null) !== 'User'
            || !is_string($user['login'] ?? null)
            || trim($user['login']) === '') {
            return null;
        }
        $id = $comment['id'] ?? null;
        $url = $comment['html_url'] ?? null;
        $body = $comment['body'] ?? null;
        if (!is_int($id) || $id < 1 || !is_string($url) || !is_string($body)) {
            return null;
        }

        // The API response binds the comment to the correct pull request.
        // A comment from another repo or issue must not become authority here.
        $expectedUrl = sprintf(
            'https://github.com/%s/pull/%d#issuecomment-%d',
            $repository,
            $pullRequestNumber,
            $id,
        );
        if ($url !== $expectedUrl) {
            return null;
        }

        // The marker is intentionally an entire, literal message. Any change
        // to the rule-text acknowledgement or the exact identity fails closed.
        $pattern = '~\ASELF_SHAPE_MAINTENANCE_V1\n'
            . 'source=MEMORY\.md\n'
            . 'base=(?<base>[a-f0-9]{40})\n'
            . 'head=(?<head>[a-f0-9]{40})\n'
            . 'before_sha256=(?<before>[a-f0-9]{64})\n'
            . 'after_sha256=(?<after>[a-f0-9]{64})\n'
            . 'diff_sha256=(?<diff>[a-f0-9]{64})\n'
            . 'decision=reference-only-maintenance\n'
            . 'rule_text_changed=yes\z~D';
        if (preg_match($pattern, trim($body), $matches) !== 1) {
            return null;
        }

        return new SelfShapeMaintenanceReviewCommentEvidence(
            new SelfShapeMaintenanceReviewClaim(
                'MEMORY.md',
                $matches['base'],
                $matches['head'],
                $matches['before'],
                $matches['after'],
                $matches['diff'],
            ),
            $user['login'],
            $id,
            $url,
        );
    }
}
