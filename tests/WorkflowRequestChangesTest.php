<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowCli;

/**
 * A human "request changes" decision on an exact candidate revision is recorded
 * distinctly from a rejection, never approves, survives archival, and is named
 * on the next revision's approval card.
 */
final class WorkflowRequestChangesTest extends TestCase
{
    private string $root;
    private string $head;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-request-changes-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src', 0o775, true);
        file_put_contents($this->root . '/src/Foo.php', "<?php\nfinal class Foo {}\n");
        file_put_contents($this->root . '/.gitignore', "/.agent-loop/\n");
        $this->git('init', '-q');
        $this->git('config', 'user.email', 'x@y.test');
        $this->git('config', 'user.name', 'X');
        $this->git('add', '.');
        $this->git('commit', '-qm', 'base');
        $this->head = $this->git('rev-parse', 'HEAD');
        [$exit, $output] = $this->cli(['plan', 'T-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', 'Change Foo.', '--validation', 'php -l src/Foo.php']);
        self::assertSame(0, $exit, $output);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    public function testRequestChangesRecordsTheDecisionAndNeverApproves(): void
    {
        [$exit, $output] = $this->cli(['request-changes', 'T-1', '--revision', '1', '--by', 'Lars Moelleken', '--reason', 'Use STANDARD and cover hardened paths.', '--head', $this->head]);
        self::assertSame(0, $exit, $output);

        $contract = $this->contract();
        self::assertSame(TaskContract::REJECTED, $contract->status);
        self::assertSame(TaskContract::CHANGE_REQUEST, $contract->rejectionKind);
        self::assertSame('Lars Moelleken', $contract->rejectedBy);
        self::assertSame('Use STANDARD and cover hardened paths.', $contract->rejectionReason);
        self::assertNotNull($contract->rejectedAt);
        self::assertNull($contract->approvedBy);
        self::assertSame('change_request', json_decode($this->read($contract->path), true)['rejection_kind']);

        [$exit] = $this->cli(['approve', 'T-1', '--revision', '1', '--by', 'Lars Moelleken']);
        self::assertSame(1, $exit, 'A revision with a change request cannot be approved.');
        self::assertNull($this->contract()->approvedBy);
    }

    /** @return iterable<string, array{list<string>}> */
    public static function refusedRequests(): iterable
    {
        yield 'stale revision' => [['--revision', '2', '--by', 'Lars', '--reason', 'r']];
        yield 'reserved actor' => [['--revision', '1', '--by', 'standing:policy', '--reason', 'r']];
        yield 'blank actor' => [['--revision', '1', '--by', ' ', '--reason', 'r']];
        yield 'missing actor' => [['--revision', '1', '--reason', 'r']];
        yield 'missing reason' => [['--revision', '1', '--by', 'Lars']];
        yield 'oversized reason' => [['--revision', '1', '--by', 'Lars', '--reason', str_repeat('x', 4001)]];
        yield 'mismatching head' => [['--revision', '1', '--by', 'Lars', '--reason', 'r', '--head', str_repeat('a', 40)]];
    }

    /** @param list<string> $arguments */
    #[DataProvider('refusedRequests')]
    public function testInvalidRequestsAreRefusedWithoutWriting(array $arguments): void
    {
        $before = $this->read($this->contract()->path);

        [$exit] = $this->cli(['request-changes', 'T-1', ...$arguments]);

        self::assertSame(1, $exit);
        self::assertSame($before, $this->read($this->contract()->path));
    }

    public function testANonCandidateContractCannotReceiveAChangeRequest(): void
    {
        [$exit] = $this->cli(['reject', 'T-1', '--revision', '1', '--by', 'Lars', '--reason', 'No.']);
        self::assertSame(0, $exit);
        $before = $this->read($this->contract()->path);

        [$exit] = $this->cli(['request-changes', 'T-1', '--revision', '1', '--by', 'Lars', '--reason', 'Change it.']);

        self::assertSame(1, $exit);
        self::assertSame($before, $this->read($this->contract()->path));
        self::assertSame(TaskContract::REJECTION, $this->contract()->rejectionKind);
    }

    public function testRejectWritesItsKindAndALegacyRejectionStillLoadsAsRejection(): void
    {
        [$exit] = $this->cli(['reject', 'T-1', '--revision', '1', '--by', 'Lars', '--reason', 'No.']);
        self::assertSame(0, $exit);
        $path = $this->contract()->path;
        $data = json_decode($this->read($path), true);
        self::assertSame('rejection', $data['rejection_kind']);

        unset($data['rejection_kind']);
        file_put_contents($path, json_encode($data));
        $legacy = $this->contract();
        self::assertSame(TaskContract::REJECTED, $legacy->status);
        self::assertSame(TaskContract::REJECTION, $legacy->rejectionKind);
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function malformedKinds(): iterable
    {
        yield 'unknown value' => ['approved', true];
        yield 'empty string' => ['', true];
        yield 'wrong type' => [1, true];
        yield 'kind on a candidate' => ['change_request', false];
    }

    #[DataProvider('malformedKinds')]
    public function testMalformedRejectionKindsFailClosed(mixed $kind, bool $rejected): void
    {
        if ($rejected) {
            [$exit] = $this->cli(['reject', 'T-1', '--revision', '1', '--by', 'Lars', '--reason', 'No.']);
            self::assertSame(0, $exit);
        }
        $path = $this->contract()->path;
        $data = json_decode($this->read($path), true);
        $data['rejection_kind'] = $kind;
        file_put_contents($path, json_encode($data));

        $this->expectException(RuntimeException::class);
        $this->contract();
    }

    public function testTheStoreRefusesAnUnsupportedKindWithoutWriting(): void
    {
        $before = $this->read($this->contract()->path);
        try {
            (new TaskContractStore($this->root))->reject('T-1', 'Lars', 1, 'r', null, 'approve');
            self::fail('An unsupported kind must be refused.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Unsupported Contract rejection kind', $exception->getMessage());
        }
        self::assertSame($before, $this->read($this->contract()->path));
    }

    public function testTheNextRevisionsCardsDistinguishChangeRequestRejectionAndPlannerRevision(): void
    {
        [$exit] = $this->cli(['request-changes', 'T-1', '--revision', '1', '--by', 'Lars Moelleken', '--reason', 'Cover hardened paths.']);
        self::assertSame(0, $exit);
        $card = $this->planRevision('Change Foo safely.');
        self::assertSame('supersedes_change_request', $card['why_now']['kind']);
        self::assertStringContainsString('Lars Moelleken', $card['why_now']['summary']);
        self::assertStringContainsString('Cover hardened paths.', $card['why_now']['summary']);

        // An ordinary planner revision of a never-decided draft stays a plain supersession.
        $card = $this->planRevision('Change Foo safely, v3.');
        self::assertSame('supersedes_draft', $card['why_now']['kind']);

        [$exit] = $this->cli(['reject', 'T-1', '--revision', '3', '--by', 'Reviewer', '--reason', 'Wrong approach.']);
        self::assertSame(0, $exit);
        $card = $this->planRevision('Change Foo differently.');
        self::assertSame('supersedes_rejection', $card['why_now']['kind']);
        self::assertStringContainsString('Reviewer', $card['why_now']['summary']);
        self::assertStringContainsString('Wrong approach.', $card['why_now']['summary']);

        // The original change request survives archival and every later revision.
        $archive = (new \voku\AgentLoop\ProjectLayout($this->root))->contractHistoryFile('T-1', 'contract.001.json');
        self::assertFileExists($archive, 'Revision 1 is archived.');
        $first = json_decode($this->read($archive), true);
        self::assertSame('change_request', $first['rejection_kind']);
        self::assertSame('Lars Moelleken', $first['rejected_by']);
        self::assertSame('Cover hardened paths.', $first['rejection_reason']);
        self::assertSame(4, $this->contract()->revision);
    }

    public function testTheApprovalCardOffersTheExactRequestChangesCommand(): void
    {
        $card = $this->planRevision('Change Foo again.');
        $choices = array_column($card['choices'], 'command', 'id');
        self::assertSame('agent-loop workflow request-changes T-1 --revision 2 --by <actor> --reason <reason>', $choices['request_changes']);
    }

    /** @return array<string, mixed> */
    private function planRevision(string $goal): array
    {
        [$exit, $output] = $this->cli(['plan', 'T-1', '--by', 'planner', '--file', 'src/Foo.php', '--goal', $goal, '--validation', 'php -l src/Foo.php', '--format=json']);
        self::assertSame(0, $exit, $output);
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        return $payload['human_decision']['card'];
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents);

        return $contents;
    }

    private function contract(): TaskContract
    {
        return (new TaskContractStore($this->root))->load('T-1');
    }

    /**
     * @param list<string> $arguments
     *
     * @return array{0: int, 1: string}
     */
    private function cli(array $arguments): array
    {
        $level = ob_get_level();
        ob_start();
        try {
            $exit = (new WorkflowCli($this->root))->run($arguments);
            $output = (string) ob_get_clean();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return [$exit, $output];
    }

    private function git(string ...$arguments): string
    {
        exec('git -C ' . escapeshellarg($this->root) . ' ' . implode(' ', array_map('escapeshellarg', $arguments)) . ' 2>&1', $output, $exit);
        self::assertSame(0, $exit, implode("\n", $output));

        return trim(implode("\n", $output));
    }
}
