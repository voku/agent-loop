<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use DateTimeImmutable;
use DateTimeInterface;
use JsonException;
use RuntimeException;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\GitWorkTree;
use voku\AgentLoop\Run\CanonicalJson;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

final readonly class ExecutionStateStore
{
    /**
     * Every this-many-th `changes_required` of a Run (counted across its review stages) needs a human decision before the Run
     * continues; the count is not reset by Attention resolution or recovery, so the next prompt comes after another full set.
     */
    public const int MAX_REVIEW_ROUNDS = 3;

    /** Consecutive failed runs of one candidate-validation stage before a human decides. */
    public const int MAX_VALIDATION_FAILURES = 3;

    public function __construct(private string $rootPath)
    {
    }

    public function prepare(ExecutionPlan $plan): ExecutionState
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            return $this->prepareUnlocked($plan);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function projection(ExecutionPlan $plan): ExecutionProjection
    {
        return $this->projectionFromState($plan, $this->loadForPlan($plan));
    }

    public function assertEvidenceClaim(ExecutionPlan $plan, ExecutionEvidenceClaim $claim): void
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            $state = $this->find($plan->taskId) ?? $this->prepareUnlocked($plan);
            $this->assertBinding($state, $plan);
            $this->authority()->assertClaimCurrent($plan, $state, $claim);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function accept(ExecutionPlan $plan, StageResult $result): ExecutionProjection
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            $state = $this->find($plan->taskId) ?? $this->prepareUnlocked($plan);
            $this->assertBinding($state, $plan);

            foreach ($state->history as $index => $accepted) {
                if ($accepted->result->submissionId !== $result->submissionId) {
                    continue;
                }
                if ($accepted->result->toArray() !== $result->toArray()) {
                    throw new RuntimeException('Stage submission id was already accepted with different content: ' . $result->submissionId);
                }
                if ($this->isStaleAfterRecovery($state, $index)) {
                    throw new RuntimeException('STALE_STAGE_RESULT: accepted stage result predates the latest candidate recovery and cannot be replayed.');
                }

                return $this->projectionFromState($plan, $state);
            }

            if ($state->attention !== null) {
                throw new RuntimeException('Execution is waiting for Attention ' . $state->attention->id . '.');
            }
            if ($state->currentStageId === null) {
                throw new RuntimeException('Execution plan is already complete.');
            }
            $this->assertResultBinding($state, $plan, $result);
            $stage = $plan->stage($state->currentStageId);
            $this->authority()->assertAcceptable($plan, $state, $stage, $result);
            $acceptedAt = $this->now();
            $history = $state->history;

            $reviewRoundsExhausted = $result->outcome === StageOutcome::CHANGES_REQUIRED
                && ($this->changesRequiredCount($history) + 1) % self::MAX_REVIEW_ROUNDS === 0;
            $repairStageId = $stage->transitions[StageOutcome::FAILED->value] ?? null;
            $validationRepair = $stage->kind === ExecutionStageKind::DETERMINISTIC
                && $result->outcome === StageOutcome::FAILED
                && $repairStageId !== null
                && $plan->stage($repairStageId)->mayMutate;
            // A repair that keeps failing the same deterministic validation is
            // not converging; without a bound an unattended host would loop.
            $validationFailures = $validationRepair ? $this->consecutiveFailures($stage->id, $history) + 1 : 0;
            $validationRepairsExhausted = $validationRepair && $validationFailures % self::MAX_VALIDATION_FAILURES === 0;
            $capHandoff = null;
            if ($reviewRoundsExhausted || $validationRepairsExhausted) {
                // The cap cannot turn a stage-illegal outcome into durable Attention.
                $reworkStageId = $stage->next($result->outcome);
                // Keep this stage's findings as the handoff to its rework stage:
                // once a human resolves the cap, that stage resumes with them.
                $capHandoff = new HandoffEnvelope(
                    $plan->taskId,
                    $plan->runId,
                    $plan->contractRevision,
                    $stage->id,
                    $reworkStageId,
                    $result->candidateRevision,
                    $plan->digest(),
                    $result->artifactReferences,
                    $result->validationReferences,
                    $acceptedAt,
                );
            }
            if ($reviewRoundsExhausted || $validationRepairsExhausted || (!$validationRepair && in_array($result->outcome, [StageOutcome::BLOCKED, StageOutcome::NEEDS_CLARIFICATION, StageOutcome::FAILED], true))) {
                $attention = new AttentionRequest(
                    'attention:' . bin2hex(random_bytes(8)),
                    $plan->taskId,
                    $plan->runId,
                    match (true) {
                        $reviewRoundsExhausted => AttentionKind::REVIEW_CHANGES_REQUIRED,
                        $validationRepairsExhausted => AttentionKind::VALIDATION_CONFLICT,
                        $result->outcome === StageOutcome::NEEDS_CLARIFICATION => AttentionKind::CLARIFICATION_REQUIRED,
                        default => AttentionKind::RUNNER_FAILED,
                    },
                    match (true) {
                        $reviewRoundsExhausted => trim(sprintf('Review requested changes in %d rounds; a human decides how to continue. %s', $this->changesRequiredCount($history) + 1, $result->summary)),
                        $validationRepairsExhausted => trim(sprintf('%s failed %d times in a row; a human decides how to continue. %s', $stage->id, $validationFailures, $result->summary)),
                        default => trim($result->summary) !== '' ? trim($result->summary) : 'Execution stage requires human attention.',
                    },
                    $stage->id,
                    $acceptedAt,
                );
                $history[] = new AcceptedStageResult($result, $capHandoff, $acceptedAt);
                $next = new ExecutionState(
                    $state->taskId,
                    $state->runId,
                    $state->contractRevision,
                    $state->executionPlanDigest,
                    $state->currentStageId,
                    $state->currentAttempt,
                    $result->candidateRevision,
                    $attention,
                    $history,
                    $state->recoveryHistory,
                    $state->lastAttentionId,
                    $this->routedReviewAttentionIdsAfterAccept($plan, $state, $stage->id, $stage->id),
                    $this->routedValidationAttentionIdsAfterAccept($plan, $state, $stage->id, $result->outcome, $stage->id),
                );
                $this->write($next);

                return $this->projectionFromState($plan, $next);
            }

            $nextStageId = $stage->next($result->outcome);
            $handoff = new HandoffEnvelope(
                $plan->taskId,
                $plan->runId,
                $plan->contractRevision,
                $stage->id,
                $nextStageId,
                $result->candidateRevision,
                $plan->digest(),
                $result->artifactReferences,
                $result->validationReferences,
                $acceptedAt,
            );
            $history[] = new AcceptedStageResult($result, $handoff, $acceptedAt);
            $nextAttempt = $nextStageId === null ? 0 : $this->attemptFor($nextStageId, $history);
            $next = new ExecutionState(
                $state->taskId,
                $state->runId,
                $state->contractRevision,
                $state->executionPlanDigest,
                $nextStageId,
                $nextAttempt,
                $result->candidateRevision,
                null,
                $history,
                $state->recoveryHistory,
                $this->carriedAttentionId($plan, $state, $nextStageId),
                $this->routedReviewAttentionIdsAfterAccept($plan, $state, $stage->id, $nextStageId),
                $this->routedValidationAttentionIdsAfterAccept($plan, $state, $stage->id, $result->outcome, $nextStageId),
            );
            $this->write($next);

            return $this->projectionFromState($plan, $next);
        } finally {
            $this->releaseLock($lock);
        }
    }

    /**
     * Reopen a completed execution on the same approved plan after the owner
     * has proven a different current workspace candidate.
     *
     * @param list<string> $changedFiles
     */
    public function recoverCompleted(
        ExecutionPlan $plan,
        string $previousCandidateRevision,
        string $observedCandidateRevision,
        array $changedFiles,
        ?string $candidateWorkspace = null,
    ): ExecutionProjection {
        $lock = $this->acquireLock($plan->taskId);
        try {
            $state = $this->find($plan->taskId) ?? $this->prepareUnlocked($plan);
            $this->assertBinding($state, $plan);

            if (hash_equals($state->candidateRevision, $observedCandidateRevision)) {
                return $this->projectionFromState($plan, $state);
            }

            $lastRecovery = $state->recoveryHistory[array_key_last($state->recoveryHistory)] ?? null;
            if (is_array($lastRecovery)
                && hash_equals($lastRecovery['previous_candidate_revision'], $previousCandidateRevision)
                && hash_equals($lastRecovery['observed_candidate_revision'], $observedCandidateRevision)
                && $state->candidateRevision === $previousCandidateRevision
                && $state->currentStageId === 'build'
                && $state->currentAttempt === $lastRecovery['build_attempt']) {
                return $this->projectionFromState($plan, $state);
            }

            if ($state->currentStageId !== null || $state->attention !== null) {
                throw new RuntimeException('RECOVERY_BLOCKED: only a completed execution can recover a changed candidate.');
            }
            if (!hash_equals($state->candidateRevision, $previousCandidateRevision)) {
                throw new RuntimeException('STALE_CANDIDATE: completed execution candidate changed while recovery was being evaluated.');
            }
            if ($changedFiles === []) {
                throw new RuntimeException('RECOVERY_BLOCKED: candidate revisions differ but no unambiguous changed files were observed.');
            }

            $lastAccepted = $state->history[array_key_last($state->history)] ?? null;
            if (!$lastAccepted instanceof AcceptedStageResult
                || $lastAccepted->result->stageId !== 'verify'
                || $lastAccepted->result->outcome !== StageOutcome::PASS
                || !hash_equals($lastAccepted->result->candidateRevision, $state->candidateRevision)
                || $plan->stage('verify')->kind !== ExecutionStageKind::DETERMINISTIC) {
                throw new RuntimeException('RECOVERY_BLOCKED: completed execution has no final PASS verification bound to its accepted candidate.');
            }

            $build = $plan->stage('build');
            if ($build->kind !== ExecutionStageKind::AGENT || !$build->mayMutate) {
                throw new RuntimeException('RECOVERY_BLOCKED: execution plan has no mutating agent build stage.');
            }
            $this->assertRecoveryCandidateInput(
                $plan,
                $previousCandidateRevision,
                $observedCandidateRevision,
                $changedFiles,
                $candidateWorkspace,
            );
            $this->assertSafeChangedFiles($changedFiles);
            $attempt = $this->attemptFor('build', $state->history);
            $recoveryHistory = $state->recoveryHistory;
            $recoveryHistory[] = [
                'recovered_at' => $this->now(),
                'previous_candidate_revision' => $previousCandidateRevision,
                'observed_candidate_revision' => $observedCandidateRevision,
                'changed_files' => $changedFiles,
                'history_count' => count($state->history),
                'build_attempt' => $attempt,
            ];
            $next = new ExecutionState(
                $state->taskId,
                $state->runId,
                $state->contractRevision,
                $state->executionPlanDigest,
                'build',
                $attempt,
                $state->candidateRevision,
                null,
                $state->history,
                $recoveryHistory,
            );
            $this->write($next);

            return $this->projectionFromState($plan, $next);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function resolveAttention(ExecutionPlan $plan, string $attentionId, ?string $actor = null, ?string $response = null, ?\voku\AgentLoop\Workflow\WorkflowAttentionProjection $reviewed = null): ExecutionProjection
    {
        $lock = $this->acquireLock($plan->taskId);
        try {
            $state = $this->find($plan->taskId) ?? $this->prepareUnlocked($plan);
            $this->assertBinding($state, $plan);
            if ($state->attention === null || $state->attention->id !== $attentionId) {
                throw new RuntimeException('No matching pending Attention exists for this execution.');
            }
            if ($state->currentStageId === null) {
                throw new RuntimeException('Completed execution cannot resolve stage Attention.');
            }
            if ($reviewed !== null && ($reviewed->attention->id !== $attentionId
                || $reviewed->attention->runId !== $state->runId
                || $reviewed->contractRevision !== $state->contractRevision
                || !hash_equals($reviewed->executionPlanDigest, $state->executionPlanDigest)
                || $reviewed->attempt !== $state->currentAttempt
                || $reviewed->candidateRevision !== $state->candidateRevision
                || $reviewed->headCommit !== GitWorkTree::headCommit($this->rootPath))) {
                throw new RuntimeException('ATTENTION_RESOLUTION_STALE: reviewed execution state has changed.');
            }
            if ($actor !== null) {
                (new AttentionResolutionStore($this->rootPath))->record(
                    $plan,
                    $state->attention,
                    $state->currentAttempt,
                    $actor,
                    $response,
                );
            }
            (new AttentionResolutionStore($this->rootPath))->assertCurrent(
                $plan,
                $state->attention,
                $state->currentAttempt,
            );

            // A review that exhausted its rounds still requested changes. Once a
            // human resolves that, the Run follows the review's own changes route
            // (e.g. back to build) so a fix made after the Attention can become
            // the next candidate; repeating the review would only re-judge the
            // old one. A capped validation repair resumes its mutation stage for
            // the same reason. Every other Attention resumes the stage that raised it.
            $resumeStageId = $state->currentStageId;
            $resumeAttempt = $state->currentAttempt + 1;
            if (in_array($state->attention->kind, [AttentionKind::REVIEW_CHANGES_REQUIRED, AttentionKind::VALIDATION_CONFLICT], true)) {
                $reworkStageId = $plan->stage($state->currentStageId)->repairRoute();
                if ($reworkStageId === null) {
                    throw new RuntimeException('Stage ' . $state->currentStageId . ' has no repair route to resume.');
                }
                $resumeStageId = $reworkStageId;
                $resumeAttempt = $this->attemptFor($reworkStageId, $state->history);
            }

            $next = new ExecutionState(
                $state->taskId,
                $state->runId,
                $state->contractRevision,
                $state->executionPlanDigest,
                $resumeStageId,
                $resumeAttempt,
                $state->candidateRevision,
                null,
                $state->history,
                $state->recoveryHistory,
                $attentionId,
                $state->attention->kind === AttentionKind::REVIEW_CHANGES_REQUIRED
                    ? $this->appendRoutedReviewAttentionId($state->routedReviewAttentionIds, $attentionId)
                    : $this->restoreLegacyRoutedReviewAttentionId($plan, $state),
                $state->attention->kind === AttentionKind::VALIDATION_CONFLICT
                    ? $this->appendRoutedValidationAttentionId(
                        $this->restoreLegacyRoutedValidationAttentionIds($plan, $state),
                        $attentionId,
                    )
                    : $this->restoreLegacyRoutedValidationAttentionIds($plan, $state),
            );
            $this->write($next);

            return $this->projectionFromState($plan, $next);
        } finally {
            $this->releaseLock($lock);
        }
    }

    public function find(string $taskId): ?ExecutionState
    {
        $path = $this->path($taskId);
        if (!is_file($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new RuntimeException('Unable to read execution state: ' . $path);
        }

        return $this->decode($contents, $path, $taskId);
    }

    public function path(string $taskId): string
    {
        return (new ProjectLayout($this->rootPath))->executionStatePath($taskId);
    }

    private function prepareUnlocked(ExecutionPlan $plan): ExecutionState
    {
        $existing = $this->find($plan->taskId);
        if ($existing !== null) {
            $this->assertBinding($existing, $plan);

            return $existing;
        }

        $state = new ExecutionState(
            $plan->taskId,
            $plan->runId,
            $plan->contractRevision,
            $plan->digest(),
            $plan->firstStageId(),
            $plan->firstStageId() === null ? 0 : 1,
            $plan->baseCommit ?? 'run:' . $plan->runId . ':initial',
            null,
            [],
        );
        $this->write($state);

        return $state;
    }

    private function loadForPlan(ExecutionPlan $plan): ExecutionState
    {
        $state = $this->find($plan->taskId) ?? $this->prepare($plan);
        $this->assertBinding($state, $plan);

        return $state;
    }

    private function assertBinding(ExecutionState $state, ExecutionPlan $plan): void
    {
        if ($state->taskId !== $plan->taskId
            || $state->runId !== $plan->runId
            || $state->contractRevision !== $plan->contractRevision
            || !hash_equals($state->executionPlanDigest, $plan->digest())) {
            throw new RuntimeException('Execution state is stale for the current governed execution plan.');
        }
    }

    private function assertResultBinding(ExecutionState $state, ExecutionPlan $plan, StageResult $result): void
    {
        if ($result->taskId !== $plan->taskId
            || $result->runId !== $plan->runId
            || $result->contractRevision !== $plan->contractRevision
            || !hash_equals($result->executionPlanDigest, $plan->digest())
            || $result->stageId !== $state->currentStageId
            || $result->attempt !== $state->currentAttempt) {
            throw new RuntimeException('Stage result does not match the current execution stage binding.');
        }
    }

    /** @param list<AcceptedStageResult> $history */
    private function attemptFor(string $stageId, array $history): int
    {
        $accepted = 0;
        foreach ($history as $entry) {
            if ($entry->result->stageId === $stageId) {
                ++$accepted;
            }
        }

        return $accepted + 1;
    }

    /**
     * Preserve a resolved validation-cap response across nested clarification
     * or another validation cap. Consume it only after the failed validation
     * stage eventually passes, not when an intermediate build asks a question.
     *
     * @return list<non-empty-string>
     */
    private function routedValidationAttentionIdsAfterAccept(
        ExecutionPlan $plan,
        ExecutionState $state,
        string $acceptedStageId,
        StageOutcome $outcome,
        ?string $nextStageId,
    ): array {
        $pendingAttentionIds = $nextStageId === null
            ? []
            : $this->restoreLegacyRoutedValidationAttentionIds($plan, $state);
        if ($pendingAttentionIds === []) {
            return [];
        }

        $pending = [];
        $store = new AttentionResolutionStore($this->rootPath);
        foreach ($pendingAttentionIds as $attentionId) {
            $resolution = $store->load($plan->taskId, $attentionId);
            if ($resolution->taskId !== $plan->taskId
                || $resolution->runId !== $plan->runId
                || $resolution->contractRevision !== $plan->contractRevision
                || !hash_equals($resolution->executionPlanDigest, $plan->digest())
                || $plan->stage($resolution->stageId)->kind !== ExecutionStageKind::DETERMINISTIC) {
                throw new RuntimeException('ATTENTION_RESOLUTION_STALE: pending validation response does not match the current execution.');
            }
            if ($acceptedStageId === $resolution->stageId && $outcome === StageOutcome::PASS) {
                continue;
            }
            $pending[] = $attentionId;
        }

        return $pending;
    }

    /**
     * Restore a validation-cap response saved by an older release as just
     * last_attention_id, before the durable pending-validation list existed.
     *
     * @return list<non-empty-string>
     */
    private function restoreLegacyRoutedValidationAttentionIds(ExecutionPlan $plan, ExecutionState $state): array
    {
        $attentionId = $state->lastAttentionId;
        if ($attentionId === null || $state->currentStageId === null) {
            return $state->routedValidationAttentionIds;
        }

        $resolution = (new AttentionResolutionStore($this->rootPath))->load($plan->taskId, $attentionId);
        if ($resolution->taskId !== $plan->taskId
            || $resolution->runId !== $plan->runId
            || $resolution->contractRevision !== $plan->contractRevision
            || !hash_equals($resolution->executionPlanDigest, $plan->digest())) {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: previous validation response does not match the current execution.');
        }

        $validationStage = $plan->stage($resolution->stageId);
        if ($validationStage->kind !== ExecutionStageKind::DETERMINISTIC
            || ($validationStage->transitions[StageOutcome::FAILED->value] ?? null) !== $state->currentStageId) {
            return $state->routedValidationAttentionIds;
        }
        foreach (array_reverse($state->history) as $accepted) {
            if ($accepted->result->stageId !== $resolution->stageId) {
                continue;
            }
            if ($accepted->result->attempt === $resolution->attempt
                && $accepted->result->outcome === StageOutcome::FAILED) {
                return $this->appendRoutedValidationAttentionId($state->routedValidationAttentionIds, $attentionId);
            }

            break;
        }

        return $state->routedValidationAttentionIds;
    }

    /**
     * @param list<non-empty-string> $ids
     * @return list<non-empty-string>
     */
    private function appendRoutedValidationAttentionId(array $ids, string $attentionId): array
    {
        if (trim($attentionId) === '') {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: validation response requires a non-empty Attention id.');
        }
        $ids[] = $attentionId;

        return array_values(array_unique($ids));
    }

    /**
     * A capped review's response remains pending while a routed repair path
     * moves toward that review's next attempt. Intermediate stages retain the
     * binding without receiving the response; the mutation stage and target
     * review receive it, and the target review consumes it.
     */
    private function carriedAttentionId(ExecutionPlan $plan, ExecutionState $state, ?string $nextStageId): ?string
    {
        if ($state->lastAttentionId === null || $nextStageId === null || $state->currentStageId === null) {
            return null;
        }
        $resolution = (new AttentionResolutionStore($this->rootPath))->load($plan->taskId, $state->lastAttentionId);

        $reviewRepairStage = $plan->stage($resolution->stageId)->transitions[StageOutcome::CHANGES_REQUIRED->value] ?? null;
        if ($reviewRepairStage !== null
            && $resolution->stageId !== $state->currentStageId
            && $plan->canReachStage($reviewRepairStage, $nextStageId)) {
            return $state->lastAttentionId;
        }

        return $resolution->stageId === $nextStageId && $resolution->stageId !== $state->currentStageId
            ? $state->lastAttentionId
            : null;
    }

    /**
     * Keep a capped review decision bound to the review it routes toward while
     * build and deterministic validation run. The target review consumes it
     * after returning a result.
     *
     * @return list<non-empty-string>
     */
    private function routedReviewAttentionIdsAfterAccept(
        ExecutionPlan $plan,
        ExecutionState $state,
        string $acceptedStageId,
        ?string $nextStageId,
    ): array {
        if ($state->routedReviewAttentionIds === [] || $nextStageId === null) {
            return [];
        }

        $pending = [];
        $store = new AttentionResolutionStore($this->rootPath);
        foreach ($state->routedReviewAttentionIds as $attentionId) {
            $resolution = $store->load($plan->taskId, $attentionId);
            if ($resolution->taskId !== $plan->taskId
                || $resolution->runId !== $plan->runId
                || $resolution->contractRevision !== $plan->contractRevision
                || !hash_equals($resolution->executionPlanDigest, $plan->digest())) {
                throw new RuntimeException('ATTENTION_RESOLUTION_STALE: routed review response does not match the current execution.');
            }

            $targetReview = $resolution->stageId;
            if ($targetReview === $acceptedStageId) {
                continue;
            }
            $repairStage = $plan->stage($targetReview)->next(StageOutcome::CHANGES_REQUIRED);
            if ($targetReview === $nextStageId
                || ($repairStage !== null && $plan->canReachStage($repairStage, $nextStageId))) {
                $pending[] = $attentionId;
            }
        }

        return $pending;
    }

    /**
     * @param list<non-empty-string> $attentionIds
     * @return list<non-empty-string>
     */
    private function appendRoutedReviewAttentionId(array $attentionIds, string $attentionId): array
    {
        $attentionId = trim($attentionId);
        if ($attentionId === '') {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: review response requires a non-empty Attention id.');
        }

        $attentionIds[] = $attentionId;

        return array_values(array_unique($attentionIds));
    }

    /**
     * Older state files did not persist routed review Attention ids. If a
     * routed build opens a second Attention before accepting its next result,
     * its previous last_attention_id is the only durable reference to the
     * capped review decision. Reconstruct that pending binding from the exact
     * review changes-required result that opened it.
     *
     * @return list<non-empty-string>
     */
    private function restoreLegacyRoutedReviewAttentionId(ExecutionPlan $plan, ExecutionState $state): array
    {
        $attentionId = $state->lastAttentionId;
        if ($attentionId === null || $state->currentStageId === null) {
            return $state->routedReviewAttentionIds;
        }

        $store = new AttentionResolutionStore($this->rootPath);
        $resolution = $store->load($plan->taskId, $attentionId);
        if ($resolution->taskId !== $plan->taskId
            || $resolution->runId !== $plan->runId
            || $resolution->contractRevision !== $plan->contractRevision
            || !hash_equals($resolution->executionPlanDigest, $plan->digest())) {
            throw new RuntimeException('ATTENTION_RESOLUTION_STALE: prior review response does not match the current execution.');
        }

        $reviewStage = $plan->stage($resolution->stageId);
        $roleId = $reviewStage->roleId;
        if ($roleId === null) {
            return $state->routedReviewAttentionIds;
        }
        $capabilities = $plan->role($roleId)->requiredCapabilities;
        if (!in_array('review', $capabilities, true) && !in_array('architecture-review', $capabilities, true)) {
            return $state->routedReviewAttentionIds;
        }

        $repairStageId = $reviewStage->next(StageOutcome::CHANGES_REQUIRED);
        if ($repairStageId === null || $repairStageId !== $state->currentStageId) {
            return $state->routedReviewAttentionIds;
        }

        foreach (array_reverse($state->history) as $accepted) {
            if ($accepted->result->stageId !== $resolution->stageId) {
                continue;
            }
            if ($accepted->result->attempt !== $resolution->attempt
                || $accepted->result->outcome !== StageOutcome::CHANGES_REQUIRED) {
                return $state->routedReviewAttentionIds;
            }

            return $this->appendRoutedReviewAttentionId($state->routedReviewAttentionIds, $attentionId);
        }

        return $state->routedReviewAttentionIds;
    }

    /** @param list<AcceptedStageResult> $history */
    private function changesRequiredCount(array $history): int
    {
        $count = 0;
        foreach ($history as $entry) {
            if ($entry->result->outcome === StageOutcome::CHANGES_REQUIRED) {
                ++$count;
            }
        }

        return $count;
    }

    /** @param list<AcceptedStageResult> $history */
    private function consecutiveFailures(string $stageId, array $history): int
    {
        $count = 0;
        foreach (array_reverse($history) as $entry) {
            if ($entry->result->stageId !== $stageId) {
                continue;
            }
            if ($entry->result->outcome !== StageOutcome::FAILED) {
                break;
            }
            ++$count;
        }

        return $count;
    }

    private function projectionFromState(ExecutionPlan $plan, ExecutionState $state): ExecutionProjection
    {
        $handoffs = [];
        foreach ($state->history as $accepted) {
            if ($accepted->handoff !== null) {
                $handoffs[] = $accepted->handoff;
            }
        }

        return new ExecutionProjection(
            $state->taskId,
            $state->runId,
            $state->contractRevision,
            $plan->profile,
            $state->executionPlanDigest,
            $state->currentStageId,
            $state->currentAttempt,
            $state->attention,
            $handoffs,
            $state->candidateRevision,
        );
    }

    private function write(ExecutionState $state): void
    {
        $path = $this->path($state->taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create execution state directory: ' . $directory);
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, CanonicalJson::pretty($state->toArray())) === false || !rename($tmp, $path)) {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
            throw new RuntimeException('Unable to write execution state: ' . $path);
        }
    }

    /** @return resource */
    private function acquireLock(string $taskId): mixed
    {
        $path = (new ProjectLayout($this->rootPath))->executionStateLockPath($taskId);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create execution lock directory: ' . $directory);
        }
        $lock = fopen($path, 'c+');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Unable to acquire execution state lock: ' . $path);
        }

        return $lock;
    }

    /** @param resource $lock */
    private function releaseLock(mixed $lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    private function decode(string $json, string $path, string $expectedTaskId): ExecutionState
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid execution state JSON in ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($data) || ($data['schema_version'] ?? null) !== '1.0' || ($data['kind'] ?? null) !== 'governed_execution_state') {
            throw new RuntimeException('Unsupported execution state schema in ' . $path . '.');
        }
        $taskId = $this->requiredString($data, 'task_id', $path);
        if ($taskId !== $expectedTaskId) {
            throw new RuntimeException('Execution state task id does not match requested task.');
        }
        $revision = $data['contract_revision'] ?? null;
        $attempt = $data['current_attempt'] ?? null;
        if (!is_int($revision) || $revision < 1 || !is_int($attempt) || $attempt < 0) {
            throw new RuntimeException('Execution state revision/attempt fields are invalid in ' . $path . '.');
        }
        $currentStageId = $data['current_stage_id'] ?? null;
        if ($currentStageId !== null && (!is_string($currentStageId) || trim($currentStageId) === '')) {
            throw new RuntimeException('Execution state current_stage_id must be a non-empty string or null.');
        }
        $lastAttentionId = $data['last_attention_id'] ?? null;
        if ($lastAttentionId !== null && (!is_string($lastAttentionId) || trim($lastAttentionId) === '')) {
            throw new RuntimeException('Execution state last_attention_id must be a non-empty string or null.');
        }
        $routedReviewAttentionIds = $data['routed_review_attention_ids'] ?? [];
        if (!is_array($routedReviewAttentionIds) || !array_is_list($routedReviewAttentionIds)) {
            throw new RuntimeException('Execution state routed_review_attention_ids must be an array.');
        }
        foreach ($routedReviewAttentionIds as $attentionId) {
            if (!is_string($attentionId) || trim($attentionId) === '') {
                throw new RuntimeException('Execution state routed_review_attention_ids must contain non-empty strings.');
            }
        }
        $routedValidationAttentionIds = $data['routed_validation_attention_ids'] ?? [];
        if (!is_array($routedValidationAttentionIds) || !array_is_list($routedValidationAttentionIds)) {
            throw new RuntimeException('Execution state routed_validation_attention_ids must be an array.');
        }
        foreach ($routedValidationAttentionIds as $attentionId) {
            if (!is_string($attentionId) || trim($attentionId) === '') {
                throw new RuntimeException('Execution state routed_validation_attention_ids must contain non-empty strings.');
            }
        }

        return new ExecutionState(
            $taskId,
            $this->requiredString($data, 'run_id', $path),
            $revision,
            $this->requiredDigest($data, 'execution_plan_digest', $path),
            is_string($currentStageId) ? trim($currentStageId) : null,
            $attempt,
            $this->requiredString($data, 'candidate_revision', $path),
            $this->decodeAttention($data['attention'] ?? null, $path),
            $this->decodeHistory($data['history'] ?? null, $path),
            $this->decodeRecoveryHistory($data['recovery_history'] ?? [], $path),
            $lastAttentionId,
            $routedReviewAttentionIds,
            $routedValidationAttentionIds,
        );
    }

    /**
     * @return list<array{
     *     recovered_at: string,
     *     previous_candidate_revision: string,
     *     observed_candidate_revision: string,
     *     changed_files: list<string>,
     *     history_count: int,
     *     build_attempt: int
     * }>
     */
    private function decodeRecoveryHistory(mixed $value, string $path): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new RuntimeException('Execution state recovery_history must be an array in ' . $path . '.');
        }
        $history = [];
        foreach ($value as $index => $entry) {
            $entryPath = $path . '#recovery_history[' . $index . ']';
            if (!is_array($entry)) {
                throw new RuntimeException('Execution recovery history entry is invalid in ' . $entryPath . '.');
            }
            $historyCount = $entry['history_count'] ?? null;
            $buildAttempt = $entry['build_attempt'] ?? null;
            if (!is_int($historyCount) || $historyCount < 1 || !is_int($buildAttempt) || $buildAttempt < 1) {
                throw new RuntimeException('Execution recovery history boundary is invalid in ' . $entryPath . '.');
            }
            $changedFiles = $entry['changed_files'] ?? null;
            if (!is_array($changedFiles) || !array_is_list($changedFiles) || $changedFiles === []) {
                throw new RuntimeException('Execution recovery changed_files must be a non-empty list in ' . $entryPath . '.');
            }
            $this->assertSafeChangedFiles($changedFiles);
            $history[] = [
                'recovered_at' => $this->requiredString($entry, 'recovered_at', $entryPath),
                'previous_candidate_revision' => $this->requiredString($entry, 'previous_candidate_revision', $entryPath),
                'observed_candidate_revision' => $this->requiredString($entry, 'observed_candidate_revision', $entryPath),
                'changed_files' => $changedFiles,
                'history_count' => $historyCount,
                'build_attempt' => $buildAttempt,
            ];
        }

        return $history;
    }

    public function assertSafeChangedFiles(mixed $paths, string $errorPrefix = 'RECOVERY_BLOCKED'): void
    {
        if (!is_array($paths) || !array_is_list($paths) || $paths === []) {
            throw new RuntimeException($errorPrefix . ': changed file evidence must be a non-empty ordered list.');
        }
        $seen = [];
        foreach ($paths as $path) {
            if (!is_string($path)
                || $path === ''
                || str_starts_with($path, '/')
                || str_contains($path, "\0")
                || str_contains($path, '\\')
                || preg_match('//u', $path) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $path) === 1) {
                throw new RuntimeException($errorPrefix . ': changed file evidence contains an ambiguous repository path.');
            }
            foreach (explode('/', $path) as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..') {
                    throw new RuntimeException($errorPrefix . ': changed file evidence contains an unsafe repository path.');
                }
            }
            if (isset($seen[$path])) {
                throw new RuntimeException($errorPrefix . ': changed file evidence contains duplicate repository paths.');
            }
            $seen[$path] = true;
        }
    }

    /** @param list<string> $changedFiles */
    private function assertRecoveryCandidateInput(
        ExecutionPlan $plan,
        string $previousCandidateRevision,
        string $observedCandidateRevision,
        array $changedFiles,
        ?string $candidateWorkspace,
    ): void {
        $root = realpath($this->rootPath);
        if (!is_string($root) || !is_dir($root)) {
            throw new RuntimeException('RECOVERY_BLOCKED: repository root cannot be resolved.');
        }
        $contract = (new TaskContractStore($root))->load($plan->taskId);
        $contractSha = hash_file('sha256', $contract->path);
        if ($contract->status !== TaskContract::APPROVED
            || $contract->taskId !== $plan->taskId
            || $contract->revision !== $plan->contractRevision
            || ($contract->baseCommit !== null && $contract->baseCommit !== $plan->baseCommit)
            || !is_string($contractSha)
            || !hash_equals($plan->contractSource['sha256'], 'sha256:' . $contractSha)) {
            throw new RuntimeException('RECOVERY_BLOCKED: current approved Contract does not match the execution plan.');
        }
        $candidateRoot = realpath($candidateWorkspace ?? $root);
        if (!is_string($candidateRoot) || !is_dir($candidateRoot)) {
            throw new RuntimeException('RECOVERY_BLOCKED: candidate workspace cannot be resolved.');
        }
        $identity = new CandidateWorkspaceIdentity();
        $identity->assertSameRepository($root, $candidateRoot);
        $identity->assertHolds(
            $candidateRoot,
            $plan->baseCommit,
            $observedCandidateRevision,
            $this->relativeStateRoot($root),
        );
        $verifiedChangedFiles = (new CompletedPipelineRecovery($root))->candidateChangedFilesWithinApprovedScope(
            $plan,
            $contract,
            $previousCandidateRevision,
            $observedCandidateRevision,
            'RECOVERY_BLOCKED',
        );
        if ($verifiedChangedFiles !== $changedFiles) {
            throw new RuntimeException(
                'RECOVERY_BLOCKED: supplied changed files do not match the owner-recomputed candidate diff.',
            );
        }
    }

    private function relativeStateRoot(string $root): ?string
    {
        $stateRoot = realpath((new ProjectLayout($root))->stateRoot());
        if (!is_string($stateRoot) || !str_starts_with($stateRoot, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', substr($stateRoot, strlen($root) + 1));
    }

    private function isStaleAfterRecovery(ExecutionState $state, int $historyIndex): bool
    {
        foreach ($state->recoveryHistory as $recovery) {
            if ($historyIndex < $recovery['history_count']) {
                return true;
            }
        }

        return false;
    }

    private function decodeAttention(mixed $value, string $path): ?AttentionRequest
    {
        if ($value === null) {
            return null;
        }
        if (!is_array($value)) {
            throw new RuntimeException('Execution state attention must be an object or null in ' . $path . '.');
        }
        $kind = AttentionKind::tryFrom($this->requiredString($value, 'kind', $path . '#attention'));
        if (!$kind instanceof AttentionKind) {
            throw new RuntimeException('Unsupported execution Attention kind in ' . $path . '.');
        }
        $stageId = $value['stage_id'] ?? null;
        if ($stageId !== null && (!is_string($stageId) || trim($stageId) === '')) {
            throw new RuntimeException('Execution Attention stage_id must be a string or null.');
        }

        return new AttentionRequest(
            $this->requiredString($value, 'id', $path . '#attention'),
            $this->requiredString($value, 'task_id', $path . '#attention'),
            $this->requiredString($value, 'run_id', $path . '#attention'),
            $kind,
            $this->requiredString($value, 'message', $path . '#attention'),
            is_string($stageId) ? trim($stageId) : null,
            $this->requiredString($value, 'created_at', $path . '#attention'),
        );
    }

    /** @return list<AcceptedStageResult> */
    private function decodeHistory(mixed $value, string $path): array
    {
        if (!is_array($value)) {
            throw new RuntimeException('Execution state history must be an array in ' . $path . '.');
        }
        $history = [];
        foreach ($value as $index => $entry) {
            if (!is_array($entry) || !is_array($entry['result'] ?? null)) {
                throw new RuntimeException('Execution state history entry is invalid in ' . $path . '.');
            }
            $result = $this->decodeResult($entry['result'], $path . '#history[' . $index . '].result');
            $handoffValue = $entry['handoff'] ?? null;
            $handoff = $handoffValue === null ? null : $this->decodeHandoff($handoffValue, $path . '#history[' . $index . '].handoff');
            $history[] = new AcceptedStageResult(
                $result,
                $handoff,
                $this->requiredString($entry, 'accepted_at', $path . '#history[' . $index . ']'),
            );
        }

        return $history;
    }

    /** @param array<string, mixed> $data */
    private function decodeResult(array $data, string $path): StageResult
    {
        $revision = $data['contract_revision'] ?? null;
        $attempt = $data['attempt'] ?? null;
        if (!is_int($revision) || $revision < 1 || !is_int($attempt) || $attempt < 1) {
            throw new RuntimeException('Stage result revision/attempt is invalid in ' . $path . '.');
        }
        $outcome = StageOutcome::tryFrom($this->requiredString($data, 'outcome', $path));
        if (!$outcome instanceof StageOutcome) {
            throw new RuntimeException('Unsupported StageResult outcome in ' . $path . '.');
        }

        return new StageResult(
            $this->requiredString($data, 'submission_id', $path),
            $this->requiredString($data, 'task_id', $path),
            $this->requiredString($data, 'run_id', $path),
            $revision,
            $this->requiredDigest($data, 'execution_plan_digest', $path),
            $this->requiredString($data, 'stage_id', $path),
            $attempt,
            $outcome,
            $this->requiredString($data, 'candidate_revision', $path),
            ExecutionArtifactValue::stringList($data['artifact_references'] ?? null, $path . '#artifact_references'),
            ExecutionArtifactValue::stringList($data['validation_references'] ?? null, $path . '#validation_references'),
            is_string($data['summary'] ?? null) ? trim($data['summary']) : '',
            array_key_exists('context_id', $data)
                ? $this->requiredString($data, 'context_id', $path)
                : null,
            ExecutionArtifactValue::stringList($data['consistency_references'] ?? [], $path . '#consistency_references'),
            ExecutionArtifactValue::stringList($data['observations'] ?? [], $path . '#observations'),
        );
    }

    private function decodeHandoff(mixed $value, string $path): HandoffEnvelope
    {
        if (!is_array($value)) {
            throw new RuntimeException('Execution handoff must be an object in ' . $path . '.');
        }
        if (($value['schema_version'] ?? null) !== '1.0' || ($value['kind'] ?? null) !== 'execution_handoff') {
            throw new RuntimeException('Unsupported execution handoff schema in ' . $path . '.');
        }
        $revision = $value['contract_revision'] ?? null;
        if (!is_int($revision) || $revision < 1) {
            throw new RuntimeException('Execution handoff Contract revision is invalid in ' . $path . '.');
        }
        $toStage = $value['to_stage'] ?? null;
        if ($toStage !== null && (!is_string($toStage) || trim($toStage) === '')) {
            throw new RuntimeException('Execution handoff to_stage must be string or null in ' . $path . '.');
        }

        return new HandoffEnvelope(
            $this->requiredString($value, 'task_id', $path),
            $this->requiredString($value, 'run_id', $path),
            $revision,
            $this->requiredString($value, 'from_stage', $path),
            is_string($toStage) ? trim($toStage) : null,
            $this->requiredString($value, 'candidate_revision', $path),
            $this->requiredDigest($value, 'execution_plan_digest', $path),
            ExecutionArtifactValue::stringList($value['artifact_references'] ?? null, $path . '#artifact_references'),
            ExecutionArtifactValue::stringList($value['validation_references'] ?? null, $path . '#validation_references'),
            $this->requiredString($value, 'accepted_at', $path),
        );
    }

    /**
     * @param array<string, mixed> $data
     * @return non-empty-string
     */
    private function requiredDigest(array $data, string $key, string $path): string
    {
        return ExecutionArtifactValue::sha256($data[$key] ?? null, $path . '#' . $key);
    }

    /**
     * @param array<string, mixed> $data
     * @return non-empty-string
     */
    private function requiredString(array $data, string $key, string $path): string
    {
        return ExecutionArtifactValue::string($data[$key] ?? null, $path . '#' . $key);
    }

    private function authority(): ExecutionStageResultAuthority
    {
        return new ExecutionStageResultAuthority($this->rootPath);
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }
}
