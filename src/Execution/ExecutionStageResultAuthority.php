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
            '/^git-worktree-v1:' . preg_quote($baseCommit, '/') . ':sha256:[a-f0-9]{64}$/',
            $candidateRevision,
        ) !== 1) {
            throw new RuntimeException('STALE_CANDIDATE: candidate identity is not derived from the governed base commit.');
        }
    }
}
