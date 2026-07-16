<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use voku\AgentLoop\Workflow\WorkflowCloseCommand;
use voku\AgentSession\SessionStore;
use voku\AgentSession\LearningDecision;
use voku\AgentSession\LearningDecisionStore;
use voku\AgentSession\ValidationEvidenceStore;
use voku\AgentSession\ValidationStatus;
use voku\AgentSession\WorkBriefStore;

final class WorkflowCloseCommandTest extends TestCase
{
    private string $root;
    private string $sessionPath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-close-' . bin2hex(random_bytes(4));
        mkdir($this->root);
        $this->writeApprovedWorkBrief();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testCloseFailsWhenRecallMetaIsMissing(): void
    {
        $result = $this->runClose(verifyExit: 0);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FAIL] recall: missing', $result['output']);
        self::assertStringContainsString('session was not closed', $result['output']);
    }

    public function testCloseFailsWhenReviewReportIsMissing(): void
    {
        $this->writeRecallMeta();

        $result = $this->runClose(verifyExit: 0);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FAIL] review: missing', $result['output']);
        self::assertStringContainsString('[ACTION REQUIRED]', $result['output']);
    }

    public function testCloseFailsWhenReviewReportStatusIsFail(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'fail']);

        $result = $this->runClose(verifyExit: 0);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('status is fail', $result['output']);
    }

    public function testCloseFailsWhenReviewReportJsonIsInvalid(): void
    {
        $this->writeRecallMeta();
        $this->writeRawReviewReport('{');

        $result = $this->runClose(verifyExit: 0);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('invalid or missing status', $result['output']);
    }

    public function testCloseFailsWhenVerifyFails(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'ok']);

        $result = $this->runClose(verifyExit: 2);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('verify failed', $result['output']);
    }

    public function testCloseRequiresOutcomeForEverySelectedGuidanceItem(): void
    {
        $this->writeRecallMeta([
            'task_id' => 'ABC-123',
            'compilation_id' => 'compilation.abc.001',
            'selected_guidance' => ['G-001'],
        ]);
        $this->writeReviewReport(['status' => 'ok']);

        $result = $this->runClose(verifyExit: 0);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('missing outcomes.jsonl for selected guidance', $result['output']);
    }

    public function testCloseFailsWhenActiveSessionHasNoApprovedWorkBrief(): void
    {
        unlink($this->sessionPath . '/approval.json');
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'ok']);

        $result = $this->runClose(verifyExit: 0);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('[FAIL] work brief: revision 1 is not approved', $result['output']);
    }

    public function testCloseSucceedsWithOkReviewStatusAndVerifyPass(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'ok']);

        $calls = [];
        $result = $this->runClose(verifyExit: 0, sessionExit: 0, calls: $calls);

        self::assertSame(0, $result['exit']);
        self::assertSame([['close', 'ABC-123', '--status', 'done']], $calls);
    }

    public function testCloseSucceedsWithWarnReviewStatusAndVerifyPass(): void
    {
        $this->writeRecallMeta();
        $this->writeReviewReport(['status' => 'warn']);

        $calls = [];
        $result = $this->runClose(verifyExit: 0, sessionExit: 0, calls: $calls);

        self::assertSame(0, $result['exit']);
        self::assertSame([['close', 'ABC-123', '--status', 'done']], $calls);
    }

    public function testCloseReadsGeneratedReviewFixtureShape(): void
    {
        $this->writeRecallMeta();
        $this->writeRawReviewReport((string) file_get_contents(__DIR__ . '/fixtures/review-reports/blindspots.warn.json'));

        $result = $this->runClose(verifyExit: 0);

        self::assertSame(0, $result['exit']);
        self::assertStringContainsString('with status warn', $result['output']);
    }

    public function testAcceptRiskWritesAcceptedRiskFileAndDelegatesDespiteFailedGates(): void
    {
        $calls = [];
        $result = $this->runClose(
            args: ['ABC-123', '--status', 'done', '--accept-risk', 'Manual review.'],
            verifyExit: 1,
            sessionExit: 0,
            calls: $calls,
        );

        self::assertSame(0, $result['exit']);
        self::assertFileExists($this->root . '/.agent-loop/risks/ABC-123.accepted-risk.md');
        self::assertStringContainsString('accepted risk recorded', $result['output']);
        self::assertSame([['close', 'ABC-123', '--status', 'done']], $calls);
    }

    public function testAcceptRiskReportsSessionCloseFailureAfterBypass(): void
    {
        $result = $this->runClose(
            args: ['ABC-123', '--status', 'done', '--accept-risk', 'Manual review.'],
            verifyExit: 1,
            sessionExit: 9,
        );

        self::assertSame(9, $result['exit']);
        self::assertStringContainsString('session close failed after accepted-risk bypass', $result['output']);
    }

    public function testAcceptRiskWithEmptyReasonFails(): void
    {
        $result = $this->runClose(args: ['ABC-123', '--status', 'done', '--accept-risk', '']);

        self::assertSame(1, $result['exit']);
        self::assertFileDoesNotExist($this->root . '/.agent-loop/risks/ABC-123.accepted-risk.md');
    }

    public function testCloseWithNonDoneStatusFailsAndSuggestsSessionClose(): void
    {
        $result = $this->runClose(args: ['ABC-123', '--status', 'dropped']);

        self::assertSame(1, $result['exit']);
        self::assertStringContainsString('Use agent-loop session close directly', $result['output']);
    }

    /**
     * @param list<string> $args
     * @param list<list<string>> $calls
     *
     * @return array{exit: int, output: string}
     */
    private function runClose(
        array $args = ['ABC-123', '--status', 'done'],
        int $verifyExit = 0,
        int $sessionExit = 0,
        array &$calls = [],
    ): array {
        $command = new WorkflowCloseCommand(
            $this->root,
            static function (array $argv) use (&$calls, $sessionExit): int {
                $calls[] = $argv;

                return $sessionExit;
            },
            static fn (array $argv): int => $verifyExit,
        );

        ob_start();
        $exit = $command->run($args);
        $output = (string) ob_get_clean();

        return ['exit' => $exit, 'output' => $output];
    }

    /** @param array<string, mixed> $meta */
    private function writeRecallMeta(array $meta = []): void
    {
        mkdir($this->root . '/recall/ABC-123', 0o775, true);
        file_put_contents($this->root . '/recall/ABC-123/meta.json', json_encode($meta, JSON_THROW_ON_ERROR));
    }

    private function writeApprovedWorkBrief(): void
    {
        $session = (new SessionStore())->create($this->root . '/session_plan', 'ABC-123');
        $this->sessionPath = $session->path;
        $briefs = new WorkBriefStore();
        $briefs->create($session, 'Keep the task scope reviewable.', ['src/Foo.php'], [], ['vendor/bin/phpunit']);
        $briefs->approve($session, 'lars');
        (new ValidationEvidenceStore())->record($session, 1, 'vendor/bin/phpunit', ValidationStatus::PASSED, 0, 10, 'lars');
        (new LearningDecisionStore())->decide($session, LearningDecision::NO_DURABLE_LEARNING, 'lars');
    }

    /** @param array<string, string> $data */
    private function writeReviewReport(array $data): void
    {
        $json = json_encode($data);
        self::assertIsString($json);

        $this->writeRawReviewReport($json);
    }

    private function writeRawReviewReport(string $json): void
    {
        if (!is_dir($this->root . '/recall/ABC-123/reviews')) {
            mkdir($this->root . '/recall/ABC-123/reviews', 0o775, true);
        }
        file_put_contents($this->root . '/recall/ABC-123/reviews/ABC-123.blindspots.json', $json);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($dir);
    }
}
