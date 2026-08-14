<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use voku\AgentLoop\GitCandidateEvidence;

/**
 * Regression for the #104/#111 outcome-honesty history.
 *
 * #104 proves that a branch may move after its earlier head was merged. #111
 * proves the other important shape: a squash merge may ship the exact candidate
 * tree even though the source commit itself is not an ancestor of the target.
 */
final class GitCandidateEvidenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        if ($this->run(sys_get_temp_dir(), ['git', '--version'])['exit'] !== 0) {
            self::markTestSkipped('git is not available.');
        }

        $this->root = sys_get_temp_dir() . '/agent-loop-candidate-evidence-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testMergedCandidateStillPassesAfterItsOldSourceBranchMoves(): void
    {
        $repo = $this->repository();
        $base = $this->git($repo, 'rev-parse', 'HEAD');
        $this->git($repo, 'switch', '-c', 'candidate');
        $this->commitFile($repo, 'candidate.txt', "candidate\n", 'candidate');
        $candidate = $this->git($repo, 'rev-parse', 'HEAD');

        $this->git($repo, 'switch', 'main');
        $this->git($repo, 'merge', '--no-ff', '--no-edit', 'candidate');
        $integrated = $this->git($repo, 'rev-parse', 'HEAD');

        $this->git($repo, 'switch', 'candidate');
        $this->commitFile($repo, 'later.txt', "not shipped by the earlier merge\n", 'later branch work');
        $laterBranchHead = $this->git($repo, 'rev-parse', 'HEAD');
        $this->git($repo, 'switch', 'main');

        $evidence = (new GitCandidateEvidence($repo))->prove($candidate, $integrated, 'main');

        self::assertSame('ancestor', $evidence['integration_kind']);
        self::assertSame($candidate, $evidence['candidate_sha']);
        self::assertSame($integrated, $evidence['integrated_sha']);
        self::assertNotSame($base, $evidence['target_sha']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('neither an ancestor');
        (new GitCandidateEvidence($repo))->prove($laterBranchHead, $integrated, 'main');
    }

    public function testExactCandidateTreeCanBeProvedThroughASquashMerge(): void
    {
        $repo = $this->repository();
        $this->git($repo, 'switch', '-c', 'candidate');
        $this->commitFile($repo, 'candidate.txt', "squash me\n", 'candidate');
        $candidate = $this->git($repo, 'rev-parse', 'HEAD');

        $this->git($repo, 'switch', 'main');
        $this->git($repo, 'merge', '--squash', 'candidate');
        $this->git($repo, 'commit', '--quiet', '--no-gpg-sign', '-m', 'integrated squash');
        $integrated = $this->git($repo, 'rev-parse', 'HEAD');

        self::assertSame(1, $this->run($repo, ['git', 'merge-base', '--is-ancestor', $candidate, $integrated])['exit']);

        $evidence = (new GitCandidateEvidence($repo))->prove($candidate, $integrated, 'main');

        self::assertSame('exact_tree', $evidence['integration_kind']);
        self::assertSame($evidence['candidate_tree'], $evidence['integrated_tree']);
    }

    public function testSquashWithAdditionalContentIsNotExactCandidateEvidence(): void
    {
        $repo = $this->repository();
        $this->git($repo, 'switch', '-c', 'candidate');
        $this->commitFile($repo, 'candidate.txt', "candidate\n", 'candidate');
        $candidate = $this->git($repo, 'rev-parse', 'HEAD');

        $this->git($repo, 'switch', 'main');
        $this->git($repo, 'merge', '--squash', 'candidate');
        file_put_contents($repo . '/extra.txt', "not part of candidate\n");
        $this->git($repo, 'add', 'extra.txt');
        $this->git($repo, 'commit', '--quiet', '--no-gpg-sign', '-m', 'changed squash');
        $integrated = $this->git($repo, 'rev-parse', 'HEAD');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('nor exact-tree-equivalent');
        (new GitCandidateEvidence($repo))->prove($candidate, $integrated, 'main');
    }

    public function testIntegratedCommitMustReachFrozenTargetAndReleaseCommit(): void
    {
        $repo = $this->repository();
        $base = $this->git($repo, 'rev-parse', 'HEAD');
        $this->commitFile($repo, 'candidate.txt', "released\n", 'candidate');
        $integrated = $this->git($repo, 'rev-parse', 'HEAD');
        $this->git($repo, 'tag', '-a', '1.2.3', '-m', 'release 1.2.3', $integrated);
        $this->commitFile($repo, 'later.txt', "after release\n", 'later');
        $this->git($repo, 'branch', 'old-target', $base);

        $evidence = (new GitCandidateEvidence($repo))->prove($integrated, $integrated, 'main', '1.2.3');

        self::assertSame('ancestor', $evidence['integration_kind']);
        self::assertSame($integrated, $evidence['release_commit_sha']);
        self::assertNotSame($integrated, $evidence['tag_object_sha'], 'Annotated tag object identity must be preserved separately.');

        try {
            (new GitCandidateEvidence($repo))->prove($integrated, $integrated, 'old-target');
            self::fail('An integrated commit outside the frozen target must fail.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('not reachable from frozen target', $exception->getMessage());
        }

        $this->git($repo, 'tag', '-a', '0.9.0', '-m', 'old release', $base);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain integrated commit');
        (new GitCandidateEvidence($repo))->prove($integrated, $integrated, 'main', '0.9.0');
    }

    public function testCandidateAndIntegratedInputsMustBeExactObjectIds(): void
    {
        $repo = $this->repository();
        $head = $this->git($repo, 'rev-parse', 'HEAD');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a full Git object ID');
        (new GitCandidateEvidence($repo))->prove('main', $head, 'main');
    }

    private function repository(): string
    {
        $repo = $this->root . '/repo';
        mkdir($repo, 0o775, true);
        $this->git($repo, 'init', '--quiet', '--initial-branch=main');
        $this->git($repo, 'config', 'user.email', 'candidate-evidence@example.invalid');
        $this->git($repo, 'config', 'user.name', 'candidate evidence fixture');
        $this->git($repo, 'config', 'commit.gpgsign', 'false');
        $this->commitFile($repo, 'README.md', "base\n", 'base');

        return $repo;
    }

    private function commitFile(string $repo, string $path, string $content, string $message): void
    {
        file_put_contents($repo . '/' . $path, $content);
        $this->git($repo, 'add', $path);
        $this->git($repo, 'commit', '--quiet', '--no-gpg-sign', '-m', $message);
    }

    private function git(string $workingDirectory, string ...$arguments): string
    {
        $result = $this->run($workingDirectory, ['git', ...$arguments]);
        self::assertSame(0, $result['exit'], trim($result['stderr']));

        return trim($result['stdout']);
    }

    /**
     * @param non-empty-list<string> $command
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function run(string $workingDirectory, array $command): array
    {
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $workingDirectory,
        );
        if (!is_resource($process)) {
            return ['exit' => 127, 'stdout' => '', 'stderr' => 'unable to start process'];
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

    private function removeDirectory(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeDirectory($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
