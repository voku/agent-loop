<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use RuntimeException;

/**
 * Reads GitHub-issued PR/comment evidence. No pull-request-controlled JSON can
 * substitute for these responses, and no review is created automatically.
 */
final readonly class SelfShapeMaintenanceGitHubReader
{
    private SelfShapeMaintenanceGitHubApi $api;

    public function __construct(
        ProcessRunner $runner,
        private SelfShapeMaintenanceReviewCommentVerifier $verifier = new SelfShapeMaintenanceReviewCommentVerifier(),
        ?SelfShapeMaintenanceGitHubApi $api = null,
    ) {
        $this->api = $api ?? new SelfShapeMaintenanceGitHubCli($runner);
    }

    /** @return list<SelfShapeMaintenanceReviewCommentEvidence> */
    public function findAll(string $repository, int $prNumber, string $expectedHead): array
    {
        if (preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~', $repository) !== 1
            || $prNumber < 1 || preg_match('/\A[a-f0-9]{40}\z/', $expectedHead) !== 1) {
            throw new RuntimeException('Invalid trusted GitHub PR identity.');
        }

        $pr = $this->api->request('repos/' . $repository . '/pulls/' . $prNumber);
        if (!is_array($pr) || !isset($pr['head']) || !is_array($pr['head'])
            || ($pr['head']['sha'] ?? null) !== $expectedHead) {
            throw new RuntimeException('GitHub PR head differs from the exact checkout being reviewed.');
        }

        $pages = $this->api->request('repos/' . $repository . '/issues/' . $prNumber . '/comments?per_page=100', true);
        if (!is_array($pages)) {
            throw new RuntimeException('Invalid GitHub issue-comments response.');
        }

        $reviews = [];
        foreach ($pages as $page) {
            if (!is_array($page)) {
                throw new RuntimeException('Invalid GitHub issue-comments page.');
            }
            foreach ($page as $comment) {
                if (!is_array($comment) || !is_string($comment['body'] ?? null)
                    || !str_starts_with($comment['body'], 'SELF_SHAPE_MAINTENANCE_V1')) {
                    continue;
                }

                $actor = $comment['user'] ?? null;
                if (!is_array($actor) || !is_string($actor['login'] ?? null)) {
                    continue;
                }
                $login = $actor['login'];
                $owner = explode('/', $repository, 2)[0];
                if (strcasecmp($login, $owner) === 0 && ($comment['author_association'] ?? null) === 'OWNER') {
                    // Owner identity and OWNER association both come from the
                    // GitHub API, not from a PR-controlled environment value.
                    $permission = 'admin';
                } else {
                    $permissionData = $this->api->request(
                        'repos/' . $repository . '/collaborators/' . rawurlencode($login) . '/permission',
                    );
                    $permission = is_array($permissionData) && is_string($permissionData['permission'] ?? null)
                        ? $permissionData['permission']
                        : '';
                }
                $evidence = $this->verifier->fromTrustedGitHubApi($comment, $permission, $repository, $prNumber);
                if ($evidence !== null && $evidence->claim->headCommit === $expectedHead) {
                    $reviews[] = $evidence;
                }
            }
        }

        return $reviews;
    }

}
