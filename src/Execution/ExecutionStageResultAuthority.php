<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use RuntimeException;

final readonly class ExecutionStageResultAuthority
{
    public function __construct(private string $rootPath)
    {
    }

    public function assertAcceptable(
        ExecutionPlan $plan,
        ExecutionState $state,
        ExecutionStage $stage,
        StageResult $result,
    ): void {
        $this->assertCandidate($plan, $state, $stage, $result->candidateRevision);

        $evidence = new ExecutionEvidenceStore($this->rootPath);
        foreach ($result->artifactReferences as $reference) {
            $evidence->assertCurrent(
                $reference,
                ExecutionEvidenceKind::ARTIFACT,
                $plan,
                $result->stageId,
                $result->attempt,
                $result->candidateRevision,
            );
        }
        foreach ($result->validationReferences as $reference) {
            $evidence->assertCurrent(
                $reference,
                ExecutionEvidenceKind::VALIDATION,
                $plan,
                $result->stageId,
                $result->attempt,
                $result->candidateRevision,
            );
        }

        if ($stage->kind === ExecutionStageKind::DETERMINISTIC
            && $result->outcome === StageOutcome::PASS
            && $result->validationReferences === []) {
            throw new RuntimeException('MISSING_EVIDENCE: deterministic verification requires current owner validation evidence.');
        }
    }

    public function assertClaimCurrent(
        ExecutionPlan $plan,
        ExecutionState $state,
        ExecutionEvidenceClaim $claim,
    ): void {
        if ($state->attention !== null) {
            throw new RuntimeException('EVIDENCE_MISMATCH: execution is waiting for Attention.');
        }
        if ($state->currentStageId === null) {
            throw new RuntimeException('EVIDENCE_MISMATCH: execution plan is already complete.');
        }
        if ($claim->taskId !== $plan->taskId
            || $claim->runId !== $plan->runId
            || $claim->contractRevision !== $plan->contractRevision
            || !hash_equals($claim->executionPlanDigest, $plan->digest())
            || $claim->stageId !== $state->currentStageId
            || $claim->attempt !== $state->currentAttempt) {
            throw new RuntimeException('EVIDENCE_MISMATCH: evidence claim does not match the current execution stage binding.');
        }

        $this->assertCandidate($plan, $state, $plan->stage($state->currentStageId), $claim->candidateRevision);
    }

    private function assertCandidate(
        ExecutionPlan $plan,
        ExecutionState $state,
        ExecutionStage $stage,
        string $candidateRevision,
    ): void {
        if (hash_equals($state->candidateRevision, $candidateRevision)) {
            return;
        }
        if (!$stage->mayMutate) {
            throw new RuntimeException('CANDIDATE_MISMATCH: non-mutating stage cannot change the governed candidate identity.');
        }
        $baseCommit = $plan->baseCommit;
        if ($baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('STALE_CANDIDATE: mutating stage has no exact governed base commit.');
        }
        if (preg_match(
            '/^git-tree-v1:' . preg_quote($baseCommit, '/') . ':([0-9a-f]{40}|[0-9a-f]{64})$/',
            $candidateRevision,
            $matches,
        ) !== 1) {
            throw new RuntimeException('STALE_CANDIDATE: candidate identity is not a content-addressed Git tree bound to the governed base commit.');
        }

        $this->assertTreeExists($matches[1]);
    }

    private function assertTreeExists(string $tree): void
    {
        $root = realpath($this->rootPath);
        if (!is_string($root)) {
            throw new RuntimeException('STALE_CANDIDATE: repository root cannot be resolved.');
        }
        $process = proc_open(
            ['git', 'cat-file', '-e', $tree . '^{tree}'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('STALE_CANDIDATE: unable to inspect candidate Git tree.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('STALE_CANDIDATE: candidate Git tree is not present in the governed repository object store: ' . trim(is_string($stderr) ? $stderr : ''));
        }
    }
}
