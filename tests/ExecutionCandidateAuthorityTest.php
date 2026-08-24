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
use voku\AgentLoop\Execution\StageResult;

final class ExecutionCandidateAuthorityTest extends TestCase
{
    private string $root;
    private string $baseCommit;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/agent-loop-candidate-authority-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0o775, true);
        $this->git(['init', '--quiet']);
        $this->git(['config', 'user.email', 'candidate-authority@example.invalid']);
        $this->git(['config', 'user.name', 'Candidate Authority Test']);
        file_put_contents($this->root . '/fixture.txt', "base\n");
        $this->git(['add', '--', 'fixture.txt']);
        $this->git(['commit', '--quiet', '-m', 'base']);
        $this->baseCommit = $this->git(['rev-parse', 'HEAD']);
    }

    protected function tearDown(): void
    {
        $this->rm($this->root);
    }

    public function testChangedCandidateRequiresCurrentOwnerCandidateEvidence(): void
    {
        $plan = $this->plan(true);
        $state = $this->state($plan, 1, $this->baseCommit);
        $stage = $plan->stage('build');
        $candidate = $this->changedCandidate();
        $result = $this->stageResult($plan, $state, $candidate);
        $authority = new ExecutionStageResultAuthority($this->root);

        try {
            $authority->assertAcceptable($plan, $state, $stage, $result);
            self::fail('A Git tree string alone must not become authoritative candidate identity.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('MISSING_EVIDENCE', $exception->getMessage());
        }

        $claim = $this->candidateClaim($plan, $state, $candidate);
        $authority->assertClaimCurrent($plan, $state, $claim);
        (new ExecutionEvidenceStore($this->root))->record($claim);

        $authority->assertAcceptable($plan, $state, $stage, $result);
        self::addToAssertionCount(1);
    }

    public function testCandidateEvidenceCannotReplayIntoAnotherAttempt(): void
    {
        $plan = $this->plan(true);
        $attemptOne = $this->state($plan, 1, $this->baseCommit);
        $candidate = $this->changedCandidate();
        $claim = $this->candidateClaim($plan, $attemptOne, $candidate);
        $authority = new ExecutionStageResultAuthority($this->root);
        $authority->assertClaimCurrent($plan, $attemptOne, $claim);
        (new ExecutionEvidenceStore($this->root))->record($claim);

        $attemptTwo = $this->state($plan, 2, $this->baseCommit);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MISSING_EVIDENCE');
        $authority->assertAcceptable(
            $plan,
            $attemptTwo,
            $plan->stage('build'),
            $this->stageResult($plan, $attemptTwo, $candidate),
        );
    }

    public function testCandidateObservationMustDeriveFromCurrentGovernedCandidate(): void
    {
        $plan = $this->plan(true);
        $state = $this->state($plan, 1, $this->baseCommit);
        $candidate = $this->changedCandidate();
        $claim = new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            1,
            $candidate,
            ExecutionEvidenceKind::CANDIDATE,
            'candidate:stale',
            'sha256:' . hash('sha256', 'candidate:stale' . "\0" . $candidate),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_CANDIDATE');
        (new ExecutionStageResultAuthority($this->root))->assertClaimCurrent($plan, $state, $claim);
    }

    public function testReadOnlyStageCannotRegisterChangedCandidate(): void
    {
        $plan = $this->plan(false);
        $state = $this->state($plan, 1, $this->baseCommit);
        $candidate = $this->changedCandidate();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CANDIDATE_MISMATCH');
        (new ExecutionStageResultAuthority($this->root))->assertClaimCurrent(
            $plan,
            $state,
            $this->candidateClaim($plan, $state, $candidate),
        );
    }

    public function testWrongBaseCandidateFailsClosed(): void
    {
        $plan = $this->plan(true);
        $state = $this->state($plan, 1, $this->baseCommit);
        $tree = $this->changedTree();
        $candidate = 'git-tree-v1:' . str_repeat('f', 40) . ':' . $tree;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('STALE_CANDIDATE');
        (new ExecutionStageResultAuthority($this->root))->assertClaimCurrent(
            $plan,
            $state,
            $this->candidateClaim($plan, $state, $candidate),
        );
    }

    public function testNonexistentTreeCandidateFailsClosed(): void
    {
        $plan = $this->plan(true);
        $state = $this->state($plan, 1, $this->baseCommit);
        $candidate = 'git-tree-v1:' . $this->baseCommit . ':' . str_repeat('a', 40);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('candidate Git object is missing or is not a tree');
        (new ExecutionStageResultAuthority($this->root))->assertClaimCurrent(
            $plan,
            $state,
            $this->candidateClaim($plan, $state, $candidate),
        );
    }

    public function testCommitObjectCannotMasqueradeAsCandidateTree(): void
    {
        $plan = $this->plan(true);
        $state = $this->state($plan, 1, $this->baseCommit);
        $candidate = 'git-tree-v1:' . $this->baseCommit . ':' . $this->baseCommit;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('candidate Git object is missing or is not a tree');
        (new ExecutionStageResultAuthority($this->root))->assertClaimCurrent(
            $plan,
            $state,
            $this->candidateClaim($plan, $state, $candidate),
        );
    }

    private function changedCandidate(): string
    {
        return 'git-tree-v1:' . $this->baseCommit . ':' . $this->changedTree();
    }

    private function changedTree(): string
    {
        file_put_contents($this->root . '/fixture.txt', "changed\n");
        $this->git(['add', '--', 'fixture.txt']);

        return $this->git(['write-tree']);
    }

    private function plan(bool $mayMutate): ExecutionPlan
    {
        $role = new ExecutionRole('builder', $mayMutate, []);
        $stage = new ExecutionStage(
            'build',
            ExecutionStageKind::AGENT,
            'builder',
            $mayMutate,
            [],
            [StageOutcome::COMPLETED->value => null],
        );

        return new ExecutionPlan(
            'ABC-123',
            'run:candidate-authority',
            1,
            ['path' => '.agent-loop/contracts/ABC-123.json', 'sha256' => 'sha256:' . str_repeat('1', 64)],
            $this->baseCommit,
            ExecutionProfileName::SURGICAL,
            [$role],
            [$stage],
            '2026-08-24T00:00:00+00:00',
        );
    }

    private function state(ExecutionPlan $plan, int $attempt, string $candidateRevision): ExecutionState
    {
        return new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            $attempt,
            $candidateRevision,
            null,
            [],
        );
    }

    private function stageResult(ExecutionPlan $plan, ExecutionState $state, string $candidateRevision): StageResult
    {
        return new StageResult(
            'submission:candidate:' . $state->currentAttempt,
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            $state->currentAttempt,
            StageOutcome::COMPLETED,
            $candidateRevision,
            [],
            [],
            'Candidate observed.',
        );
    }

    private function candidateClaim(ExecutionPlan $plan, ExecutionState $state, string $candidateRevision): ExecutionEvidenceClaim
    {
        return new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            'build',
            $state->currentAttempt,
            $candidateRevision,
            ExecutionEvidenceKind::CANDIDATE,
            $state->candidateRevision,
            'sha256:' . hash('sha256', $state->candidateRevision . "\0" . $candidateRevision),
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
        if ($exit !== 0 || !is_string($stdout)) {
            throw new RuntimeException('Git test fixture command failed: ' . trim(is_string($stderr) ? $stderr : ''));
        }

        return trim($stdout);
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
