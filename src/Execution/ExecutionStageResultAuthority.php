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

        $stage = $plan->stage($state->currentStageId);
        if ($claim->kind === ExecutionEvidenceKind::CANDIDATE) {
            $this->assertCandidateClaim($plan, $state, $stage, $claim);

            return;
        }

        $this->assertCandidate($plan, $state, $stage, $claim->candidateRevision);
        if ($claim->kind === ExecutionEvidenceKind::ARTIFACT) {
            $this->assertArtifactClaim($plan, $claim);
        }
    }

    private function assertCandidateClaim(
        ExecutionPlan $plan,
        ExecutionState $state,
        ExecutionStage $stage,
        ExecutionEvidenceClaim $claim,
    ): void {
        if ($stage->kind !== ExecutionStageKind::AGENT || !$stage->mayMutate) {
            throw new RuntimeException('CANDIDATE_MISMATCH: candidate observations are accepted only for mutating agent stages.');
        }
        if (!hash_equals($state->candidateRevision, $claim->sourceReference)) {
            throw new RuntimeException('STALE_CANDIDATE: candidate observation does not derive from the current governed candidate.');
        }
        if (hash_equals($state->candidateRevision, $claim->candidateRevision)) {
            throw new RuntimeException('CANDIDATE_MISMATCH: unchanged candidate state does not require an external candidate observation.');
        }
        $expectedDigest = $this->candidateObservationDigest($state->candidateRevision, $claim->candidateRevision);
        if (!hash_equals($expectedDigest, $claim->sourceDigest)) {
            throw new RuntimeException('EVIDENCE_MISMATCH: candidate observation digest does not match its candidate lineage.');
        }

        $this->assertCandidateObject($plan, $claim->candidateRevision);
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

        $this->assertCandidateObject($plan, $candidateRevision);

        $claim = new ExecutionEvidenceClaim(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            $stage->id,
            $state->currentAttempt,
            $candidateRevision,
            ExecutionEvidenceKind::CANDIDATE,
            $state->candidateRevision,
            $this->candidateObservationDigest($state->candidateRevision, $candidateRevision),
        );
        $evidence = new ExecutionEvidenceStore($this->rootPath);
        $evidence->assertCurrent(
            $evidence->referenceFor($claim),
            ExecutionEvidenceKind::CANDIDATE,
            $plan,
            $stage->id,
            $state->currentAttempt,
            $candidateRevision,
        );
    }

    private function assertCandidateObject(ExecutionPlan $plan, string $candidateRevision): void
    {
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

        $this->assertTreeObject($matches[1]);
    }

    private function assertTreeObject(string $tree): void
    {
        $root = realpath($this->rootPath);
        if (!is_string($root)) {
            throw new RuntimeException('STALE_CANDIDATE: repository root cannot be resolved.');
        }
        $process = proc_open(
            ['git', 'cat-file', '-t', $tree],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('STALE_CANDIDATE: unable to inspect candidate Git object.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || trim(is_string($stdout) ? $stdout : '') !== 'tree') {
            throw new RuntimeException('STALE_CANDIDATE: candidate Git object is missing or is not a tree: ' . trim(is_string($stderr) ? $stderr : ''));
        }
    }

    private function assertArtifactClaim(ExecutionPlan $plan, ExecutionEvidenceClaim $claim): void
    {
        $prefix = 'workspace-file:';
        if (!str_starts_with($claim->sourceReference, $prefix)) {
            throw new RuntimeException('EVIDENCE_MISMATCH: external artifact evidence must reference a workspace file.');
        }
        $relativePath = substr($claim->sourceReference, strlen($prefix));
        if ($relativePath === ''
            || str_starts_with($relativePath, '/')
            || str_contains($relativePath, "\0")
            || str_contains($relativePath, '\\')) {
            throw new RuntimeException('EVIDENCE_MISMATCH: artifact evidence uses an invalid workspace-relative path.');
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new RuntimeException('EVIDENCE_MISMATCH: artifact evidence uses an unsafe workspace-relative path.');
            }
        }

        $treeish = $this->candidateTreeish($plan, $claim->candidateRevision);
        $root = realpath($this->rootPath);
        if (!is_string($root)) {
            throw new RuntimeException('STALE_EVIDENCE: repository root cannot be resolved for artifact verification.');
        }
        $process = proc_open(
            ['git', 'cat-file', 'blob', $treeish . ':' . $relativePath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('MISSING_EVIDENCE: unable to inspect artifact in the governed candidate.');
        }
        fclose($pipes[0]);
        $hash = hash_init('sha256');
        while (!feof($pipes[1])) {
            $chunk = fread($pipes[1], 1024 * 1024);
            if ($chunk === false) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_terminate($process);
                proc_close($process);
                throw new RuntimeException('MISSING_EVIDENCE: unable to read artifact from the governed candidate.');
            }
            hash_update($hash, $chunk);
        }
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('MISSING_EVIDENCE: artifact is not present as a Git blob in the governed candidate: ' . trim(is_string($stderr) ? $stderr : ''));
        }
        $actualDigest = 'sha256:' . hash_final($hash);
        if (!hash_equals($actualDigest, $claim->sourceDigest)) {
            throw new RuntimeException('EVIDENCE_MISMATCH: artifact digest does not match the governed candidate content.');
        }
    }

    private function candidateTreeish(ExecutionPlan $plan, string $candidateRevision): string
    {
        $baseCommit = $plan->baseCommit;
        if ($baseCommit === null || preg_match('/^[0-9a-f]{40,64}$/', $baseCommit) !== 1) {
            throw new RuntimeException('STALE_EVIDENCE: artifact evidence has no exact governed Git base.');
        }
        if (hash_equals($baseCommit, $candidateRevision)) {
            return $baseCommit;
        }
        if (preg_match(
            '/^git-tree-v1:' . preg_quote($baseCommit, '/') . ':([0-9a-f]{40}|[0-9a-f]{64})$/',
            $candidateRevision,
            $matches,
        ) !== 1) {
            throw new RuntimeException('STALE_EVIDENCE: artifact evidence candidate is not bound to the governed Git base.');
        }

        return $matches[1];
    }

    /** @return non-empty-string */
    private function candidateObservationDigest(string $previousCandidateRevision, string $candidateRevision): string
    {
        return 'sha256:' . hash('sha256', $previousCandidateRevision . "\0" . $candidateRevision);
    }
}
