<?php

declare(strict_types=1);

namespace voku\AgentLoop\Dogfood;

use JsonException;
use RuntimeException;

/**
 * Reads GitHub-issued PR/comment evidence. No pull-request-controlled JSON can
 * substitute for these responses, and no review is created automatically.
 */
final readonly class SelfShapeMaintenanceGitHubReader
{
    public function __construct(
        private ProcessRunner $runner,
        private SelfShapeMaintenanceReviewCommentVerifier $verifier = new SelfShapeMaintenanceReviewCommentVerifier(),
    ) {
    }

    public function find(string $repository, int $prNumber, string $expectedHead): ?SelfShapeMaintenanceReviewCommentEvidence
    {
        if (preg_match('~\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z~', $repository) !== 1
            || $prNumber < 1 || preg_match('/\A[a-f0-9]{40}\z/', $expectedHead) !== 1) {
            throw new RuntimeException('Invalid trusted GitHub PR identity.');
        }

        $pr = $this->api('repos/' . $repository . '/pulls/' . $prNumber);
        if (!is_array($pr) || !isset($pr['head']) || !is_array($pr['head'])
            || ($pr['head']['sha'] ?? null) !== $expectedHead) {
            throw new RuntimeException('GitHub PR head differs from the exact checkout being reviewed.');
        }

        $pages = $this->api('repos/' . $repository . '/issues/' . $prNumber . '/comments?per_page=100', true);
        if (!is_array($pages)) {
            throw new RuntimeException('Invalid GitHub issue-comments response.');
        }

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
                    $permissionData = $this->api(
                        'repos/' . $repository . '/collaborators/' . rawurlencode($login) . '/permission',
                    );
                    $permission = is_array($permissionData) && is_string($permissionData['permission'] ?? null)
                        ? $permissionData['permission']
                        : '';
                }
                $evidence = $this->verifier->fromTrustedGitHubApi($comment, $permission, $repository, $prNumber);
                if ($evidence !== null && $evidence->claim->headCommit === $expectedHead) {
                    return $evidence;
                }
            }
        }

        return null;
    }

    /** @return mixed */
    private function api(string $endpoint, bool $paginate = false): mixed
    {
        // gh reads GH_TOKEN from the runner environment. Never put credentials
        // on argv, write them to files, or call a shell command string.
        $args = $paginate
            ? ['gh', 'api', '--paginate', '--slurp', $endpoint]
            : ['gh', 'api', $endpoint];
        $result = $this->runner->run($args);
        if ($result['exit_code'] !== 0) {
            throw new RuntimeException('Unable to independently verify GitHub API evidence (' . $endpoint . '): '
                . trim($result['stderr']));
        }

        try {
            return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Malformed GitHub API response (' . $endpoint . ').', 0, $exception);
        }
    }
}
