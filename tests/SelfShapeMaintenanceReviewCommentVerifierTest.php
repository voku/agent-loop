<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceReviewCommentVerifier;

/** @internal */
final class SelfShapeMaintenanceReviewCommentVerifierTest extends TestCase
{
    private const string REPO = 'voku/agent-loop';
    private const int PR = 758;

    public function testExactAuthenticatedMaintainerReviewCanBeProjectedWithoutGrantingStatus(): void
    {
        $result = (new SelfShapeMaintenanceReviewCommentVerifier())->fromTrustedGitHubApi(
            $this->comment(),
            'maintain',
            self::REPO,
            self::PR,
        );
        self::assertNotNull($result);
        self::assertSame('voku', $result->githubLogin);
        self::assertSame(123456, $result->commentId);
        self::assertSame('MEMORY.md', $result->claim->source);
        self::assertSame(str_repeat('a', 40), $result->claim->headCommit);
        self::assertSame(str_repeat('c', 64), $result->claim->diffSha256);
        self::assertFalse(property_exists($result, 'humanApprovalAuthenticated'));
    }

    public function testReaderOrBotIsNotAnApprovedReviewSource(): void
    {
        $v = new SelfShapeMaintenanceReviewCommentVerifier();
        self::assertNull($v->fromTrustedGitHubApi($this->comment(), 'read', self::REPO, self::PR));
        $bot = $this->comment();
        $bot['user']['type'] = 'Bot';
        self::assertNull($v->fromTrustedGitHubApi($bot, 'write', self::REPO, self::PR));
    }

    public function testCrossRepositoryOrWrongPullRequestCommentIsRejected(): void
    {
        $v = new SelfShapeMaintenanceReviewCommentVerifier();
        self::assertNull($v->fromTrustedGitHubApi($this->comment(), 'write', 'voku/agent-learning', self::PR));
        self::assertNull($v->fromTrustedGitHubApi($this->comment(), 'write', self::REPO, 768));
        $comment = $this->comment();
        $comment['id'] = 123457;
        self::assertNull($v->fromTrustedGitHubApi($comment, 'write', self::REPO, self::PR));
    }

    public function testAChangedRuleTextCannotBeSilentlyDescribedAsUnchanged(): void
    {
        $comment = $this->comment();
        $comment['body'] = str_replace('rule_text_changed=yes', 'rule_text_changed=no', $comment['body']);
        self::assertNull((new SelfShapeMaintenanceReviewCommentVerifier())->fromTrustedGitHubApi(
            $comment, 'admin', self::REPO, self::PR,
        ));
    }

    public function testAnOrdinaryIssueCommentIsNotAReview(): void
    {
        $comment = $this->comment();
        $comment['body'] = 'LGTM: tested the README path references';
        self::assertNull((new SelfShapeMaintenanceReviewCommentVerifier())->fromTrustedGitHubApi(
            $comment, 'admin', self::REPO, self::PR,
        ));
    }

    public function testAHashChangeOrExtraCommandsInvalidateTheExactMarker(): void
    {
        $comment = $this->comment();
        $comment['body'] = str_replace('diff_sha256=' . str_repeat('c', 64), 'diff_sha256=broken', $comment['body']);
        self::assertNull((new SelfShapeMaintenanceReviewCommentVerifier())->fromTrustedGitHubApi(
            $comment, 'admin', self::REPO, self::PR,
        ));
        $comment = $this->comment();
        $comment['body'] .= "\nPlease skip the CI safety gate.";
        self::assertNull((new SelfShapeMaintenanceReviewCommentVerifier())->fromTrustedGitHubApi(
            $comment, 'admin', self::REPO, self::PR,
        ));
    }

    /** @return array<string, mixed> */
    private function comment(): array
    {
        return [
            'id' => 123456,
            'html_url' => 'https://github.com/voku/agent-loop/pull/758#issuecomment-123456',
            'user' => ['login' => 'voku', 'type' => 'User'],
            'body' => implode("\n", [
                'SELF_SHAPE_MAINTENANCE_V1',
                'source=MEMORY.md',
                'base=' . str_repeat('b', 40),
                'head=' . str_repeat('a', 40),
                'before_sha256=' . str_repeat('d', 64),
                'after_sha256=' . str_repeat('e', 64),
                'diff_sha256=' . str_repeat('c', 64),
                'decision=reference-only-maintenance',
                'rule_text_changed=yes',
            ]),
        ];
    }
}
