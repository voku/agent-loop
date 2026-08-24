<?php

declare(strict_types=1);

namespace voku\AgentLoop\Execution;

use RuntimeException;
use voku\AgentLoop\AgentLoopVerifier;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\Run\GovernedRun;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;

/**
 * Public typed boundary for external execution hosts.
 *
 * The gateway projects already-governed work and accepts candidate stage
 * results. It never delegates approval or workflow truth to the caller.
 */
final readonly class ExecutionGateway
{
    public const string COMPLETION_MARKER = 'AGENT_LOOP_STAGE_RESULT ';

    public function __construct(private string $rootPath)
    {
    }

    public function selectProfile(string $taskId, ExecutionProfileName $profile, string $actor): ExecutionProfileSelection
    {
        return (new ExecutionProfileSelectionStore($this->rootPath))->select($taskId, $profile, $actor);
    }

    public function projection(string $taskId): ExecutionProjection
    {
        [$contract, $run, $plan] = $this->current($taskId);
        unset($contract, $run);

        return (new ExecutionStateStore($this->rootPath))->projection($plan);
    }

    public function prepareStage(string $taskId, string $stageId): StageExecutionBundle
    {
        return $this->prepareStageBundle($taskId, $stageId, null);
    }

    public function prepareStageForEnvironment(
        string $taskId,
        string $stageId,
        ExecutionEnvironmentObservation $observation,
    ): StageExecutionBundle {
        return $this->prepareStageBundle($taskId, $stageId, $observation);
    }

    /** @return non-empty-string */
    public function recordStageCandidate(StageCandidateObservation $observation): string
    {
        [, , $plan] = $this->current($observation->taskId);
        $stage = $plan->stage($observation->stageId);
        if ($stage->kind !== ExecutionStageKind::AGENT || !$stage->mayMutate) {
            throw new RuntimeException('CANDIDATE_MISMATCH: external candidate observations are accepted only for mutating agent stages.');
        }

        $claim = new ExecutionEvidenceClaim(
            $observation->taskId,
            $observation->runId,
            $observation->contractRevision,
            $observation->executionPlanDigest,
            $observation->stageId,
            $observation->attempt,
            $observation->candidateRevision,
            ExecutionEvidenceKind::CANDIDATE,
            $observation->previousCandidateRevision,
            'sha256:' . hash('sha256', $observation->previousCandidateRevision . "\0" . $observation->candidateRevision),
        );
        $state = new ExecutionStateStore($this->rootPath);
        $state->assertEvidenceClaim($plan, $claim);

        return (new ExecutionEvidenceStore($this->rootPath))->record($claim);
    }

    /** @return non-empty-string */
    public function recordStageArtifact(StageArtifactObservation $observation): string
    {
        [, , $plan] = $this->current($observation->taskId);
        $stage = $plan->stage($observation->stageId);
        if ($stage->kind !== ExecutionStageKind::AGENT) {
            throw new RuntimeException('EVIDENCE_MISMATCH: external artifact observations are accepted only for agent stages.');
        }

        $claim = new ExecutionEvidenceClaim(
            $observation->taskId,
            $observation->runId,
            $observation->contractRevision,
            $observation->executionPlanDigest,
            $observation->stageId,
            $observation->attempt,
            $observation->candidateRevision,
            ExecutionEvidenceKind::ARTIFACT,
            $observation->sourceReference,
            $observation->sourceDigest,
        );
        $state = new ExecutionStateStore($this->rootPath);
        $state->assertEvidenceClaim($plan, $claim);

        return (new ExecutionEvidenceStore($this->rootPath))->record($claim);
    }

    public function submitStageResult(StageResult $result): ExecutionProjection
    {
        [, , $plan] = $this->current($result->taskId);

        return (new ExecutionStateStore($this->rootPath))->accept($plan, $result);
    }

    public function resolveAttention(string $taskId, string $attentionId): ExecutionProjection
    {
        [, , $plan] = $this->current($taskId);

        return (new ExecutionStateStore($this->rootPath))->resolveAttention($plan, $attentionId);
    }

    public function runDeterministicStage(string $taskId, string $stageId): ExecutionProjection
    {
        $bundle = $this->prepareStage($taskId, $stageId);
        if ($bundle->kind !== ExecutionStageKind::DETERMINISTIC || $stageId !== 'verify') {
            throw new RuntimeException('Only the governed deterministic verify stage is executable by this gateway.');
        }

        ob_start();
        try {
            $exit = (new AgentLoopVerifier($this->rootPath))->run(['--task-id=' . $taskId]);
        } finally {
            $output = (string) ob_get_clean();
        }

        $command = 'agent-loop verify --task-id=' . $taskId;
        $validationReference = (new ExecutionEvidenceStore($this->rootPath))->record(new ExecutionEvidenceClaim(
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $bundle->candidateRevision,
            ExecutionEvidenceKind::VALIDATION,
            $command,
            'sha256:' . hash('sha256', $command . "\0" . $exit . "\0" . $output),
        ));

        return $this->submitStageResult(new StageResult(
            $this->deterministicSubmissionId($bundle),
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $exit === 0 ? StageOutcome::PASS : StageOutcome::FAILED,
            $bundle->candidateRevision,
            [],
            [$validationReference],
            trim($output),
        ));
    }

    private function prepareStageBundle(
        string $taskId,
        string $stageId,
        ?ExecutionEnvironmentObservation $environment,
    ): StageExecutionBundle {
        [$contract, $run, $plan] = $this->current($taskId);
        $projection = (new ExecutionStateStore($this->rootPath))->projection($plan);
        if ($projection->attention !== null) {
            throw new RuntimeException('Execution is waiting for Attention ' . $projection->attention->id . '.');
        }
        if ($projection->currentStageId === null) {
            throw new RuntimeException('Execution plan is already complete.');
        }
        if ($projection->currentStageId !== $stageId) {
            throw new RuntimeException(sprintf(
                'Execution stage %s is not current; expected %s.',
                $stageId,
                $projection->currentStageId,
            ));
        }

        $stage = $plan->stage($stageId);
        if ($environment !== null) {
            if ($stage->kind !== ExecutionStageKind::AGENT) {
                throw new RuntimeException('ENVIRONMENT_MISMATCH: bounded environment observation is accepted only for agent stages.');
            }
            $this->assertEnvironmentObservation($environment, $contract, $run, $plan, $stage, $projection);
        }

        $acceptedOutcomes = $this->acceptedOutcomes($stage);
        $priorHandoff = null;
        foreach (array_reverse($projection->handoffs) as $handoff) {
            if ($handoff->toStage === $stageId) {
                $priorHandoff = $handoff;
                break;
            }
        }

        return new StageExecutionBundle(
            $taskId,
            $run->runId,
            $contract->revision,
            $plan->digest(),
            $stage->id,
            $projection->currentAttempt,
            $stage->kind,
            $stage->roleId,
            $stage->mayMutate,
            $this->repositoryRoot(),
            $plan->baseCommit,
            $projection->candidateRevision,
            $plan->contractSource,
            $this->recallSource($taskId),
            $this->nonEmptyLines($contract->scope, 'Contract scope'),
            $this->nonEmptyLines($contract->validation, 'Contract validation'),
            $priorHandoff,
            $acceptedOutcomes,
            self::COMPLETION_MARKER,
            $this->prompt($contract, $plan, $stage, $projection->currentAttempt, $acceptedOutcomes, $environment),
            $environment?->digest(),
        );
    }

    private function assertEnvironmentObservation(
        ExecutionEnvironmentObservation $observation,
        TaskContract $contract,
        GovernedRun $run,
        ExecutionPlan $plan,
        ExecutionStage $stage,
        ExecutionProjection $projection,
    ): void {
        $matches = $observation->taskId === $contract->taskId
            && $observation->runId === $run->runId
            && $observation->contractRevision === $contract->revision
            && $observation->executionPlanDigest === $plan->digest()
            && $observation->stageId === $stage->id
            && $observation->attempt === $projection->currentAttempt
            && $observation->candidateRevision === $projection->candidateRevision;

        if (!$matches) {
            throw new RuntimeException('STALE_ENVIRONMENT_OBSERVATION: observation does not match the current governed stage binding.');
        }
    }

    /** @return array{TaskContract, GovernedRun, ExecutionPlan} */
    private function current(string $taskId): array
    {
        $contract = (new TaskContractStore($this->rootPath))->load($taskId);
        if ($contract->status !== TaskContract::APPROVED) {
            throw new RuntimeException('Execution requires the current Contract to be approved.');
        }
        $run = (new GovernedRunStore($this->rootPath))->findForContract($contract);
        if (!$run instanceof GovernedRun) {
            throw new RuntimeException('Execution requires a governed Run bound to the current approved Contract.');
        }
        $plan = (new ExecutionPlanStore($this->rootPath))->prepare($run, $contract);
        (new ExecutionStateStore($this->rootPath))->prepare($plan);

        return [$contract, $run, $plan];
    }

    /** @return array{path: non-empty-string, sha256: non-empty-string}|null */
    private function recallSource(string $taskId): ?array
    {
        $path = (new ProjectLayout($this->rootPath))->recallRoot() . '/' . $taskId . '/system.md';
        if (!is_file($path)) {
            return null;
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new RuntimeException('Unable to hash governed Recall source: ' . $path);
        }
        $relative = PathResolver::relativeTo($this->rootPath, $path);
        if ($relative === '') {
            throw new RuntimeException('Unable to resolve governed Recall source path.');
        }

        return ['path' => $relative, 'sha256' => 'sha256:' . $sha];
    }

    /** @return list<StageOutcome> */
    private function acceptedOutcomes(ExecutionStage $stage): array
    {
        $accepted = [];
        foreach (array_keys($stage->transitions) as $outcome) {
            $typed = StageOutcome::tryFrom($outcome);
            if ($typed instanceof StageOutcome) {
                $accepted[] = $typed;
            }
        }
        foreach ([StageOutcome::BLOCKED, StageOutcome::NEEDS_CLARIFICATION, StageOutcome::FAILED] as $outcome) {
            if (!in_array($outcome, $accepted, true)) {
                $accepted[] = $outcome;
            }
        }

        return $accepted;
    }

    /**
     * @param list<string> $values
     * @return list<non-empty-string>
     */
    private function nonEmptyLines(array $values, string $label): array
    {
        $result = [];
        foreach ($values as $value) {
            $value = trim($value);
            if ($value === '') {
                throw new RuntimeException($label . ' contains an empty entry.');
            }
            $result[] = $value;
        }

        return $result;
    }

    /** @param list<StageOutcome> $acceptedOutcomes */
    private function prompt(
        TaskContract $contract,
        ExecutionPlan $plan,
        ExecutionStage $stage,
        int $attempt,
        array $acceptedOutcomes,
        ?ExecutionEnvironmentObservation $environment,
    ): string {
        $outcomes = implode('|', array_map(static fn (StageOutcome $outcome): string => $outcome->value, $acceptedOutcomes));
        $lines = [
            '# Governed execution stage',
            '',
            'Task: ' . $contract->taskId,
            'Run: ' . $plan->runId,
            'Contract revision: ' . $contract->revision,
            'Execution plan: ' . $plan->digest(),
            'Stage: ' . $stage->id,
            'Attempt: ' . $attempt,
            'Role: ' . ($stage->roleId ?? 'deterministic'),
            'Mutation allowed: ' . ($stage->mayMutate ? 'yes' : 'no'),
            '',
            'Goal: ' . $contract->goal,
            'Allowed scope: ' . implode(', ', $contract->scope),
            'Required validation: ' . implode(' | ', $contract->validation),
            '',
            'Stay inside the approved Contract and stage role. Repository facts and owner evidence outrank this prose.',
            'Do not commit, push, merge, rewrite unrelated work, or modify files outside the approved scope.',
            'A successful process exit is not workflow approval. Return only candidate work/evidence; agent-loop validates the transition.',
        ];

        if ($environment !== null) {
            $lines[] = '';
            $lines[] = '# Current bounded execution environment';
            $lines[] = 'Observation digest: ' . $environment->digest();
            $lines[] = 'Host: ' . $environment->hostId;
            foreach ($environment->tools as $tool) {
                $toolLine = 'Tool ' . $tool->id . ': ' . ($tool->available ? 'available' : 'unavailable');
                if ($tool->version !== null) {
                    $toolLine .= ' (' . $tool->version . ')';
                }
                $lines[] = $toolLine;
            }
            $lines[] = 'Network available: ' . $this->availability($environment->networkAvailable);
            $lines[] = 'Remote write available: ' . $this->availability($environment->remoteWriteAvailable);
            $lines[] = 'These values are bounded current execution facts, not task policy, workflow approval, or permission to widen scope.';
            $lines[] = 'Do not infer missing capabilities, credentials, environment variables, repository permissions, or owner decisions from this observation.';
        }

        $lines[] = '';
        $lines[] = '# Completion protocol';
        $lines[] = 'Your final non-empty output line must start with the exact marker below and contain one JSON object on the same line.';
        $lines[] = 'Allowed outcomes for this stage: ' . $outcomes;
        $lines[] = self::COMPLETION_MARKER . '{"outcome":"<allowed-outcome>","summary":"<brief factual summary>","artifact_references":[],"validation_references":[]}';
        $lines[] = 'Do not place Markdown fences around that final line. The marker is transport syntax, not workflow approval.';

        $recallPath = (new ProjectLayout($this->rootPath))->recallRoot() . '/' . $contract->taskId . '/system.md';
        if (is_file($recallPath)) {
            $recall = file_get_contents($recallPath);
            if (is_string($recall) && trim($recall) !== '') {
                $lines[] = '';
                $lines[] = '# Governed Recall';
                $lines[] = trim($recall);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function availability(?bool $value): string
    {
        return match ($value) {
            true => 'yes',
            false => 'no',
            null => 'unknown',
        };
    }

    private function deterministicSubmissionId(StageExecutionBundle $bundle): string
    {
        return 'deterministic:sha256:' . hash('sha256', implode("\0", [
            $bundle->taskId,
            $bundle->runId,
            (string) $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            (string) $bundle->attempt,
        ]));
    }

    private function repositoryRoot(): string
    {
        $root = realpath($this->rootPath);
        if (!is_string($root)) {
            throw new RuntimeException('Repository root cannot be resolved for execution.');
        }

        return str_replace('\\', '/', $root);
    }
}
