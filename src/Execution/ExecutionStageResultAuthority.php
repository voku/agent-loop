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
        $this->assertExecutionContext($plan, $state, $stage, $result);

        $evidence = new ExecutionEvidenceStore($this->rootPath);
        $artifactClaims = [];
        foreach ($result->artifactReferences as $reference) {
            $claim = $evidence->assertCurrent(
                $reference,
                ExecutionEvidenceKind::ARTIFACT,
                $plan,
                $result->stageId,
                $result->attempt,
                $result->candidateRevision,
            );
            if (
                str_starts_with($claim->sourceReference, 'workspace-file:')
                || str_starts_with($claim->sourceReference, 'git-base-file:')
            ) {
                // Evidence may have been persisted through any owner-side
                // entry point. Recheck file claims at acceptance so a reviewer
                // cannot pass by directly storing a stale or misclassified
                // artifact claim.
                $this->assertArtifactClaim($plan, $claim);
            }
            $artifactClaims[] = $claim;
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

        foreach ($result->consistencyReferences as $reference) {
            $evidence->assertCurrent(
                $reference,
                ExecutionEvidenceKind::CONSISTENCY,
                $plan,
                $result->stageId,
                $result->attempt,
                $result->candidateRevision,
            );
        }

        $this->assertReviewEvidence($plan, $stage, $result, $artifactClaims);

        if ($stage->kind === ExecutionStageKind::DETERMINISTIC) {
            // Validation references are the Contract commands verify executed on the candidate (each asserted current
            // above); the consistency check itself is never one of them, and verify produces no artifacts.
            if ($result->artifactReferences !== []) {
                throw new RuntimeException('EVIDENCE_MISMATCH: deterministic verification cannot claim artifacts.');
            }
            if ($result->outcome === StageOutcome::PASS && count($result->consistencyReferences) !== 1) {
                throw new RuntimeException('MISSING_EVIDENCE: deterministic verification requires one current owner consistency receipt.');
            }
        } elseif ($result->consistencyReferences !== []) {
            throw new RuntimeException('EVIDENCE_MISMATCH: only deterministic stages may submit consistency receipts.');
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

    private function assertExecutionContext(
        ExecutionPlan $plan,
        ExecutionState $state,
        ExecutionStage $stage,
        StageResult $result,
    ): void {
        if ($stage->kind !== ExecutionStageKind::AGENT
            || in_array($result->outcome, [StageOutcome::BLOCKED, StageOutcome::NEEDS_CLARIFICATION, StageOutcome::FAILED], true)) {
            return;
        }

        if (!$plan->requiresContextId($stage->id)) {
            return;
        }
        if ($result->contextId === null) {
            throw new RuntimeException(
                'MISSING_CONTEXT_EVIDENCE: stage ' . $stage->id
                . ' requires an observable host context id for independent-review lineage.',
            );
        }
        if ($stage->contextPolicy !== ExecutionContextPolicy::FRESH_REQUIRED) {
            return;
        }

        foreach ($stage->requires as $requiredStageId) {
            $requiredStage = $plan->stage($requiredStageId);
            if ($requiredStage->kind !== ExecutionStageKind::AGENT) {
                continue;
            }

            $predecessor = $this->latestAcceptedResultForStage($state, $requiredStageId);
            if (!$predecessor instanceof StageResult || $predecessor->contextId === null) {
                throw new RuntimeException(
                    'MISSING_CONTEXT_EVIDENCE: fresh stage ' . $stage->id
                    . ' cannot prove isolation from predecessor ' . $requiredStageId . '.',
                );
            }
            if (hash_equals($predecessor->contextId, $result->contextId)) {
                throw new RuntimeException(
                    'CONTEXT_REUSE_FORBIDDEN: fresh stage ' . $stage->id
                    . ' reused predecessor context ' . $requiredStageId . '.',
                );
            }
        }
    }

    private function latestAcceptedResultForStage(ExecutionState $state, string $stageId): ?StageResult
    {
        foreach (array_reverse($state->history) as $accepted) {
            if ($accepted->result->stageId === $stageId) {
                return $accepted->result;
            }
        }

        return null;
    }

    /**
     * @param list<ExecutionEvidenceClaim> $artifactClaims
     */
    private function assertReviewEvidence(
        ExecutionPlan $plan,
        ExecutionStage $stage,
        StageResult $result,
        array $artifactClaims,
    ): void {
        if ($stage->kind !== ExecutionStageKind::AGENT || $stage->roleId === null) {
            return;
        }

        $capabilities = $plan->role($stage->roleId)->requiredCapabilities;
        if (!in_array('review', $capabilities, true) && !in_array('architecture-review', $capabilities, true)) {
            return;
        }
        if (!in_array($result->outcome, [StageOutcome::PASS, StageOutcome::CHANGES_REQUIRED], true)) {
            return;
        }
        if ($result->outcome === StageOutcome::CHANGES_REQUIRED && $artifactClaims === []) {
            throw new RuntimeException(
                'MISSING_EVIDENCE: review stage ' . $stage->id
                . ' must cite current candidate files before returning ' . $result->outcome->value . '.',
            );
        }
        if ($result->outcome === StageOutcome::PASS) {
            $this->assertCompleteReviewCoverage($plan, $result, $artifactClaims);
        }
    }

    /**
     * A review PASS must reference every file changed from the governed base.
     *
     * @param list<ExecutionEvidenceClaim> $artifactClaims
     */
    private function assertCompleteReviewCoverage(
        ExecutionPlan $plan,
        StageResult $result,
        array $artifactClaims,
    ): void {
        if ($plan->baseCommit === null) {
            throw new RuntimeException('MISSING_EVIDENCE: review PASS is not bound to the governed Git candidate.');
        }
        if (hash_equals($plan->baseCommit, $result->candidateRevision)) {
            $candidateTree = $plan->baseCommit;
        } elseif (preg_match(
            '/^git-tree-v1:' . preg_quote($plan->baseCommit, '/') . ':([0-9a-f]{40}|[0-9a-f]{64})$/',
            $result->candidateRevision,
            $matches,
        ) === 1) {
            $candidateTree = $matches[1];
        } else {
            throw new RuntimeException('MISSING_EVIDENCE: review PASS is not bound to the governed Git candidate.');
        }

        $root = realpath($this->rootPath);
        if (!is_string($root)) {
            throw new RuntimeException('MISSING_EVIDENCE: repository root cannot be resolved for review coverage.');
        }
        $process = proc_open(
            ['git', 'diff', '--no-ext-diff', '--name-only', '-z', '--no-renames', $plan->baseCommit, $candidateTree, '--'],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('MISSING_EVIDENCE: unable to inspect changed files for review coverage.');
        }
        fclose($pipes[0]);
        $changedOutput = stream_get_contents($pipes[1]);
        $errorOutput = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_string($changedOutput)) {
            throw new RuntimeException(
                'MISSING_EVIDENCE: unable to inspect changed files for review coverage: '
                . trim(is_string($errorOutput) ? $errorOutput : ''),
            );
        }

        $reviewed = [];
        foreach ($artifactClaims as $claim) {
            foreach (['workspace-file:', 'git-base-file:'] as $prefix) {
                if (str_starts_with($claim->sourceReference, $prefix)) {
                    $reviewed[substr($claim->sourceReference, strlen($prefix))] = true;
                    break;
                }
            }
        }
        $changed = array_values(array_filter(explode("\0", $changedOutput), static fn (string $path): bool => $path !== ''));
        $missing = array_values(array_filter($changed, static fn (string $path): bool => !isset($reviewed[$path])));
        if ($missing !== []) {
            throw new RuntimeException(
                'MISSING_EVIDENCE: review PASS must cite every changed candidate file; missing: '
                . implode(', ', $missing),
            );
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
        $workspacePrefix = 'workspace-file:';
        $basePrefix = 'git-base-file:';
        $candidateTree = null;
        if (str_starts_with($claim->sourceReference, $workspacePrefix)) {
            $treeish = $this->candidateTreeish($plan, $claim->candidateRevision);
            $relativePath = substr($claim->sourceReference, strlen($workspacePrefix));
        } elseif (str_starts_with($claim->sourceReference, $basePrefix)) {
            $stage = $plan->stage($claim->stageId);
            $capabilities = $stage->roleId === null ? [] : $plan->role($stage->roleId)->requiredCapabilities;
            if (!in_array('review', $capabilities, true) && !in_array('architecture-review', $capabilities, true)) {
                throw new RuntimeException('EVIDENCE_MISMATCH: base-file evidence is accepted only for review stages.');
            }
            $treeish = $plan->baseCommit
                ?? throw new RuntimeException('STALE_EVIDENCE: deleted-file evidence has no governed Git base.');
            $candidateTree = $this->candidateTreeish($plan, $claim->candidateRevision);
            $relativePath = substr($claim->sourceReference, strlen($basePrefix));
        } else {
            throw new RuntimeException('EVIDENCE_MISMATCH: external artifact evidence must reference a candidate or deleted base file.');
        }
        if (preg_match('/^[0-9a-f]{40,64}$/', $treeish) !== 1) {
            throw new RuntimeException('STALE_EVIDENCE: artifact source is not bound to an exact Git object.');
        }
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

        $root = realpath($this->rootPath);
        if (!is_string($root)) {
            throw new RuntimeException('STALE_EVIDENCE: repository root cannot be resolved for artifact verification.');
        }
        if ($candidateTree !== null) {
            $this->assertPathAbsentFromCandidate($candidateTree, $relativePath, $root);
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

    private function assertPathAbsentFromCandidate(string $candidateTree, string $relativePath, string $root): void
    {
        $process = proc_open(
            ['git', 'cat-file', '-e', $candidateTree . ':' . $relativePath],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $root,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('MISSING_EVIDENCE: unable to verify deleted-file evidence against the candidate.');
        }
        fclose($pipes[0]);
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit === 0) {
            throw new RuntimeException('EVIDENCE_MISMATCH: git-base-file evidence is valid only when the path is absent from the candidate.');
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
