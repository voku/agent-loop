<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

final class TaskContractBaseCommitResolutionTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if (self::git('--version') === null) {
            self::markTestSkipped('Git is required.');
        }

        $this->root = sys_get_temp_dir() . '/agent-loop-base-exact-' . bin2hex(random_bytes(6));
        if (!mkdir($this->root, 0o775, true)) {
            throw new RuntimeException('Unable to create Git test fixture.');
        }

        self::assertNotNull(self::git('-C', $this->root, 'init', '--quiet', '--initial-branch=main'));
        self::assertNotNull(self::git('-C', $this->root, 'config', 'user.email', 'fixture@example.invalid'));
        self::assertNotNull(self::git('-C', $this->root, 'config', 'user.name', 'Fixture'));
        self::assertNotNull(self::git('-C', $this->root, 'config', 'commit.gpgsign', 'false'));
        file_put_contents($this->root . '/README.md', "fixture\n");
        self::assertNotNull(self::git('-C', $this->root, 'add', 'README.md'));
        self::assertNotNull(self::git('-C', $this->root, 'commit', '--quiet', '--no-gpg-sign', '-m', 'fixture'));
        file_put_contents($this->root . '/README.md', "fixture second\n");
        self::assertNotNull(self::git('-C', $this->root, 'add', 'README.md'));
        self::assertNotNull(self::git('-C', $this->root, 'commit', '--quiet', '--no-gpg-sign', '-m', 'second'));
    }

    protected function tearDown(): void
    {
        if (!isset($this->root) || !is_dir($this->root)) {
            return;
        }

        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->root);
    }

    /** @return iterable<string, array{string}> */
    public static function validRevisions(): iterable
    {
        yield 'short commit' => ['short'];
        yield 'branch' => ['main'];
        yield 'relative commit' => ['HEAD~1'];
        yield 'full commit' => ['full'];
    }

    #[DataProvider('validRevisions')]
    public function testPlanStoresOnlyAnExactCommit(string $kind): void
    {
        $head = GitWorkTree::headCommit($this->root);
        self::assertNotNull($head);
        $revision = match ($kind) {
            'short' => substr($head, 0, 9),
            'full' => $head,
            default => $kind,
        };
        $expected = $kind === 'relative commit'
            ? self::git('-C', $this->root, 'rev-parse', 'HEAD~1')
            : $head;

        $store = new TaskContractStore($this->root);
        $contract = $store->create('BASE-1', 'Resolve Git base.', ['README.md'], [], ['true'], 'fixture', $revision);
        self::assertSame($expected, $contract->baseCommit);
        self::assertSame($expected, $store->load('BASE-1')->baseCommit);
        self::assertSame(TaskContract::APPROVED, $store->approve('BASE-1', 'fixture', 1)->status);
        self::assertMatchesRegularExpression('/^[a-f0-9]{40,64}$/D', (string) $contract->baseCommit);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidRevisions(): iterable
    {
        yield 'unknown SHA' => ['ffffffffffffffffffffffffffffffffffffffff'];
        yield 'missing ref' => ['no-such-ref'];
        yield 'tree object' => ['HEAD^{tree}'];
        yield 'blob object' => ['HEAD:README.md'];
        yield 'option injection' => ['--help'];
    }

    #[DataProvider('invalidRevisions')]
    public function testInvalidBaseIsRefusedBeforeAnyContractIsWritten(string $revision): void
    {
        $store = new TaskContractStore($this->root);
        try {
            $store->create('BASE-1', 'Reject invalid base.', ['README.md'], [], ['true'], 'fixture', $revision);
            self::fail('Invalid Git base unexpectedly accepted: ' . $revision);
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must resolve to exactly one Git commit', $exception->getMessage());
        }

        self::assertNull($store->find('BASE-1'));
        self::assertSame([], $store->supersededRevisions('BASE-1'));
    }

    public function testInvalidSupersessionPreservesApprovedRevisionAndHistory(): void
    {
        $store = new TaskContractStore($this->root);
        $initial = $store->create('BASE-1', 'Keep approved base.', ['README.md'], [], ['true'], 'fixture', 'HEAD');
        $store->approve('BASE-1', 'fixture', 1);

        try {
            $store->revise('BASE-1', 'Attempt invalid base.', ['README.md'], [], ['true'], 'fixture', 'missing-ref');
            self::fail('Invalid supersession unexpectedly accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('must resolve to exactly one Git commit', $exception->getMessage());
        }

        self::assertSame(1, $store->load('BASE-1')->revision);
        self::assertSame(TaskContract::APPROVED, $store->load('BASE-1')->status);
        self::assertSame($initial->baseCommit, $store->load('BASE-1')->baseCommit);
        self::assertSame([], $store->supersededRevisions('BASE-1'));
    }

    public function testAbsentBaseAndNonGitRootPreservePreviousBehavior(): void
    {
        $store = new TaskContractStore($this->root);
        self::assertNull($store->create('BASE-1', 'No base.', ['README.md'], [], ['true'], 'fixture')->baseCommit);

        $plain = sys_get_temp_dir() . '/agent-loop-base-plain-' . bin2hex(random_bytes(5));
        mkdir($plain, 0o775, true);
        try {
            $plainStore = new TaskContractStore($plain);
            self::assertSame('short-base', $plainStore->create(
                'BASE-2', 'Plain root.', ['src/Foo.php'], [], ['true'], 'fixture', 'short-base',
            )->baseCommit);
        } finally {
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($plain, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            ) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($plain);
        }
    }

    private static function git(string ...$arguments): ?string
    {
        $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            return null;
        }
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($stdout) ? trim($stdout) : null;
    }
}
