<?php

declare(strict_types=1);

namespace voku\AgentLoop\Tests;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use voku\AgentLoop\Execution\ExecutionEvidenceClaim;
use voku\AgentLoop\Execution\ExecutionEvidenceKind;
use voku\AgentLoop\Execution\ExecutionEvidenceStore;
use voku\AgentLoop\Execution\ExecutionPlan;
use voku\AgentLoop\Execution\ExecutionProfileName;
use voku\AgentLoop\Execution\ExecutionRole;
use voku\AgentLoop\Execution\ExecutionStage;
use voku\AgentLoop\Execution\ExecutionStageKind;
use voku\AgentLoop\Execution\ExecutionStageResultAuthority;
use voku\AgentLoop\Execution\ExecutionState;
use voku\AgentLoop\Execution\StageOutcome;

final class ExecutionArtifactAuthorityTest extends TestCase
{
    private string $root;
    private string $baseCommit;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-artifact-authority-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
        $this->git(['init', '--quiet']);
        $this->git(['config', 'user.email', 'artifact-authority@example.invalid']);
        $this->git(['config', 'user.name', 'Artifact Authority Test']);
        file_put_contents($this->root . '/artifact.txt', "base artifact\n");
        $this->git(['add', '--', 'artifact.txt']);
        $this->git(['commit', '--quiet', '-m', 'base']);
        $this->baseCommit = $this->git(['rev-parse', 'HEAD']);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testArtifactDigestIsReverifiedAgainstChangedCandidateTree(): void
    {
        [$plan, $state, $authority, $candidate] = $this->changedCandidateContext();
        $claim = $this->artifactClaim(
            $plan,
            $candidate,
            'workspace-file:artifact.txt',
            'sha256:' . hash('sha256', "changed artifact\n"),
        );

        $authority->assertClaimCurrent($plan, $state, $claim);

        self::addToAssertionCount(1);
    }

    public function testForgedArtifactDigestFailsClosed(): void
    {
        [$plan, $state, $authority, $candidate] = $this->changedCandidateContext();
        $claim = $this->artifactClaim(
            $plan,
            $candidate,
            'workspace-file:artifact.txt',
            'sha256:' . str_repeat('0', 64),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('artifact digest does not match the governed candidate content');
        $authority->assertClaimCurrent($plan, $state, $claim);
    }

    public function testMissingArtifactFailsClosed(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);
        $claim = $this->artifactClaim(
            $plan,
            $state->candidateRevision,
            'workspace-file:missing.txt',
            'sha256:' . hash('sha256', 'missing'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('artifact is not present as a Git blob in the governed candidate');
        (new ExecutionStageResultAuthority($this->root))->assertClaimCurrent($plan, $state, $claim);
    }

    public function testArtifactReferenceCannotTraverseCandidateTree(): void
    {
        $plan = $this->plan();
        $state = $this->state($plan);
        $claim = $this->artifactClaim(
            $plan,
            $state->candidateRevision,
            'workspace-file:../artifact.txt',
            'sha256:' . hash('sha256', "base artifact\n"),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unsafe workspace-relative path');
        (new ExecutionStageResultAuthority($this->root))->assertClaimCurrent($plan, $state, $claim);
    }

    /** @return array{ExecutionPlan, ExecutionState, ExecutionStageResultAuthority, non-empty-string} */
    private function changedCandidateContext(): array
    {
        $plan = $this->plan();
        $state = $this->state($plan);
        file_put_contents($this->root . '/artifact.txt', "changed artifact\n");
        $this->git(['add', '--', 'artifact.txt']);
        $tree = $this->git(['write-tree']);
        $candidate = 'git-tree-v1:' . $this->baseCommit . ':' . $tree;
        $authority = new ExecutionStageResultAuthority($this->root);
        $candidateClaim = new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            1,
            $candidate,
            ExecutionEvidenceKind::CANDIDATE,
            $state->candidateRevision,
            'sha256:' . hash('sha256', $state->candidateRevision . "\0" . $candidate),
        );
        $authority->assertClaimCurrent($plan, $state, $candidateClaim);
        (new ExecutionEvidenceStore($this->root))->record($candidateClaim);

        return [$plan, $state, $authority, $candidate];
    }

    private function plan(): ExecutionPlan
    {
        $role = new ExecutionRole('builder', true, []);
        $stage = new ExecutionStage(
            'build',
            ExecutionStageKind::AGENT,
            'builder',
            true,
            [],
            [StageOutcome::COMPLETED->value => null],
        );

        return new ExecutionPlan(
            'ABC-123',
            'run:artifact-authority',
            1,
            ['path' => '.agent-loop/contracts/ABC-123.json', 'sha256' => 'sha256:' . str_repeat('1', 64)],
            $this->baseCommit,
            ExecutionProfileName::SURGICAL,
            [$role],
            [$stage],
            '2026-08-24T00:00:00+00:00',
        );
    }

    private function state(ExecutionPlan $plan): ExecutionState
    {
        return new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            1,
            $this->baseCommit,
            null,
            [],
        );
    }

    private function artifactClaim(
        ExecutionPlan $plan,
        string $candidateRevision,
        string $sourceReference,
        string $sourceDigest,
    ): ExecutionEvidenceClaim {
        return new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            1,
            $candidateRevision,
            ExecutionEvidenceKind::ARTIFACT,
            $sourceReference,
            $sourceDigest,
        );
    }

    /** @param list<string> $arguments */
    private function git(array $arguments): string
    {
        $process = proc_open(
            ['git', ...$arguments],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to execute Git test fixture command.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('Git test fixture command failed: ' . trim(is_string($stderr) ? $stderr : ''));
        }

        return trim(is_string($stdout) ? $stdout : '');
    }

    private function rm(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
