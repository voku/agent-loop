<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Dogfood\ProcessRunner;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceGitHubApi;
use voku\AgentLoop\Dogfood\SelfShapeMaintenanceGitHubReader;

/** @internal */
final class SelfShapeMaintenanceGitHubReaderTest extends TestCase
{
    private const string REPO = 'voku/agent-loop';
    private const string HEAD = 'f1d1f7b91a7adad974aa88ca5104135894d43196';

    public function testOwnerCommentIsAttributedOnlyWhenGithubReportsOwner(): void
    {
        $comment = $this->comment();
        $comment['author_association'] = 'OWNER';
        $reader = $this->reader([
            'repos/voku/agent-loop/pulls/758' => ['head' => ['sha' => self::HEAD]],
            'repos/voku/agent-loop/issues/758/comments?per_page=100' => [[$comment]],
        ]);

        $evidence = $reader->find(self::REPO, 758, self::HEAD);
        self::assertNotNull($evidence);
        self::assertSame(758123, $evidence->commentId);
        self::assertSame(self::HEAD, $evidence->claim->headCommit);
    }

    public function testCollaboratorPermissionMustComeFromIndependentEndpoint(): void
    {
        $comment = $this->comment();
        $comment['user']['login'] = 'contributor';
        $comment['html_url'] = 'https://github.com/voku/agent-loop/pull/758#issuecomment-758123';

        $responses = [
            'repos/voku/agent-loop/pulls/758' => ['head' => ['sha' => self::HEAD]],
            'repos/voku/agent-loop/issues/758/comments?per_page=100' => [[$comment]],
            'repos/voku/agent-loop/collaborators/contributor/permission' => ['permission' => 'write'],
        ];
        self::assertNotNull($this->reader($responses)->find(self::REPO, 758, self::HEAD));

        $responses['repos/voku/agent-loop/collaborators/contributor/permission'] = ['permission' => 'read'];
        self::assertNull($this->reader($responses)->find(self::REPO, 758, self::HEAD));
    }

    public function testWrongHeadFailsBeforeTrustingAnyReview(): void
    {
        $reader = $this->reader([
            'repos/voku/agent-loop/pulls/758' => ['head' => ['sha' => str_repeat('a', 40)]],
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('head differs');
        $reader->find(self::REPO, 758, self::HEAD);
    }

    public function testNoMatchingReviewDoesNotBecomeApproval(): void
    {
        $reader = $this->reader([
            'repos/voku/agent-loop/pulls/758' => ['head' => ['sha' => self::HEAD]],
            'repos/voku/agent-loop/issues/758/comments?per_page=100' => [[], [['body' => 'LGTM']]],
        ]);
        self::assertNull($reader->find(self::REPO, 758, self::HEAD));
    }

    public function testGithubRateLimitFailsClosedRatherThanReturningFalseReview(): void
    {
        $reader = $this->reader([
            'repos/voku/agent-loop/pulls/758' => new RuntimeException('GitHub API HTTP 403 rate limit exceeded'),
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('rate limit exceeded');
        $reader->find(self::REPO, 758, self::HEAD);
    }

    public function testUnexpectedPaginationShapeFailsClosed(): void
    {
        $reader = $this->reader([
            'repos/voku/agent-loop/pulls/758' => ['head' => ['sha' => self::HEAD]],
            'repos/voku/agent-loop/issues/758/comments?per_page=100' => ['unexpected' => 'shape'],
        ]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid GitHub issue-comments page');
        $reader->find(self::REPO, 758, self::HEAD);
    }

    /** @param array<string, mixed> $responses */
    private function reader(array $responses): SelfShapeMaintenanceGitHubReader
    {
        $api = new class ($responses) implements SelfShapeMaintenanceGitHubApi {
            /** @param array<string, mixed> $responses */
            public function __construct(private readonly array $responses)
            {
            }

            public function request(string $endpoint, bool $paginate = false): mixed
            {
                if ($paginate !== str_contains($endpoint, '/comments?')) {
                    throw new RuntimeException('Unexpected pagination contract: ' . $endpoint);
                }
                if (!array_key_exists($endpoint, $this->responses)) {
                    throw new RuntimeException('Unexpected GitHub API call: ' . $endpoint);
                }
                $response = $this->responses[$endpoint];
                if ($response instanceof RuntimeException) {
                    throw $response;
                }

                return $response;
            }
        };

        return new SelfShapeMaintenanceGitHubReader(new ProcessRunner(__DIR__), api: $api);
    }

    /** @return array<string, mixed> */
    private function comment(): array
    {
        return [
            'id' => 758123,
            'html_url' => 'https://github.com/voku/agent-loop/pull/758#issuecomment-758123',
            'author_association' => 'OWNER',
            'user' => ['type' => 'User', 'login' => 'voku'],
            'body' => implode("\n", [
                'SELF_SHAPE_MAINTENANCE_V1',
                'source=MEMORY.md',
                'base=622c004e2cc642fce1c06dc63a4f04d4889637e2',
                'head=' . self::HEAD,
                'before_sha256=' . str_repeat('b', 64),
                'after_sha256=' . str_repeat('c', 64),
                'diff_sha256=' . str_repeat('d', 64),
                'decision=reference-only-maintenance',
                'rule_text_changed=yes',
            ]),
        ];
    }
}
