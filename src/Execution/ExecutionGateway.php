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
        [$contract, $run, $plan] = $this->current($taskId);
        $states = new ExecutionStateStore($this->rootPath);
        $state = $states->find($taskId) ?? $states->prepare($plan);
        $projection = $states->projection($plan);
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
        $acceptedOutcomes = [];
        foreach (array_keys($stage->transitions) as $outcome) {
            $typed = StageOutcome::tryFrom($outcome);
            if ($typed instanceof StageOutcome) {
                $acceptedOutcomes[] = $typed;
            }
        }
        foreach ([StageOutcome::BLOCKED, StageOutcome::NEEDS_CLARIFICATION, StageOutcome::FAILED] as $outcome) {
            if (!in_array($outcome, $acceptedOutcomes, true)) {
                $acceptedOutcomes[] = $outcome;
            }
        }

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
            $state->currentAttempt,
            $stage->kind,
            $stage->roleId,
            $stage->mayMutate,
            $this->repositoryRoot(),
            $contract->baseCommit,
            $state->candidateRevision,
            $plan->contractSource,
            $this->recallSource($taskId),
            $contract->scope,
            $contract->validation,
            $priorHandoff,
            $acceptedOutcomes,
            $this->prompt($contract, $plan, $stage, $state->currentAttempt),
        );
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

        return $this->submitStageResult(new StageResult(
            'deterministic:' . bin2hex(random_bytes(8)),
            $bundle->taskId,
            $bundle->runId,
            $bundle->contractRevision,
            $bundle->executionPlanDigest,
            $bundle->stageId,
            $bundle->attempt,
            $exit === 0 ? StageOutcome::PASS : StageOutcome::FAILED,
            $bundle->candidateRevision,
            [],
            ['agent-loop verify --task-id=' . $taskId],
            trim($output),
        ));
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

    private function prompt(TaskContract $contract, ExecutionPlan $plan, ExecutionStage $stage, int $attempt): string
    {
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
            'A successful process exit is not workflow approval. Return only candidate work/evidence; agent-loop validates the transition.',
        ];
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

    private function repositoryRoot(): string
    {
        $root = realpath($this->rootPath);
        if (!is_string($root)) {
            throw new RuntimeException('Repository root cannot be resolved for execution.');
        }

        return str_replace('\\', '/', $root);
    }
}
