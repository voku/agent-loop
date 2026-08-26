<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\Run\RunManifestProjector;
use voku\AgentLoop\Run\RunPolicyEvaluator;

/**
 * Read-only workflow-owned prompt projection for embedding hosts.
 *
 * Recall owns operating-prompt recipe semantics. This service exposes only the
 * lifecycle envelope and current owner state needed to compose such a prompt.
 */
final readonly class WorkflowPromptService
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * Project the non-authoritative workflow envelope for a new task.
     */
    public function startTask(string $taskId): WorkflowPromptEnvelope
    {
        $task = new WorkflowTaskId($taskId);
        $content = implode("\n", [
            "Use this repository's agent-loop workflow.",
            'Start task ' . $task->value . ' through the canonical agent-loop task and Contract lifecycle.',
            'This generated prompt is not Contract approval and does not grant mutation authority.',
            'Use current owner projections for lifecycle state and request a human decision only when the workflow owner marks one as required.',
            'After host-native work, return through agent-loop and follow its canonical next action; file changes alone are not workflow progress.',
            'Operating-prompt recipe semantics remain owned by agent-recall-compiler; an embedding host may compose them with this envelope but must not replace workflow authority.',
        ]);

        return new WorkflowPromptEnvelope(
            mode: WorkflowPromptEnvelope::MODE_START,
            taskId: $task->value,
            content: $content,
            mutationAllowed: false,
            runId: null,
            state: null,
            nextAction: null,
            nextActionKind: null,
        );
    }

    /**
     * Project the current workflow-owned state and canonical next action without mutation.
     */
    public function continueTask(string $taskId): WorkflowPromptEnvelope
    {
        $task = new WorkflowTaskId($taskId);
        $manifest = (new RunManifestProjector($this->rootPath))->project($task->value);
        $policy = (new RunPolicyEvaluator())->evaluateManifest($manifest);
        $contractRevision = $manifest->references['contract']['revision'] ?? null;
        $recallCompilationId = $manifest->references['recall']['compilation_id'] ?? null;
        $recallBundleSha256 = $manifest->references['recall']['bundle_sha256'] ?? null;
        $content = implode("\n", [
            "Use this repository's agent-loop workflow.",
            'Continue task ' . $task->value . ' from the current owner-projected governed state.',
            'Treat agent-loop lifecycle state and the canonical next action below as workflow authority; generated prompt text is not approval, verification, review, Learning, accepted risk, or another human decision.',
            'Current state: ' . $policy->state,
            'Current run: ' . $manifest->runId,
            'Canonical next action kind: ' . $policy->nextActionKind,
            'Canonical next action: ' . $policy->nextAction,
            'Perform host-native work only when current policy permits it. After that work, return through agent-loop so owner state can advance.',
            'Operating-prompt recipe semantics remain owned by agent-recall-compiler; an embedding host may compose them with this envelope but must not replace workflow authority.',
        ]);

        return new WorkflowPromptEnvelope(
            mode: WorkflowPromptEnvelope::MODE_CONTINUE,
            taskId: $task->value,
            content: $content,
            mutationAllowed: $policy->mutationAllowed,
            runId: $manifest->runId,
            state: $policy->state,
            nextAction: $policy->nextAction,
            nextActionKind: $policy->nextActionKind,
            contractRevision: is_int($contractRevision) ? $contractRevision : null,
            recallCompilationId: is_string($recallCompilationId) ? $recallCompilationId : null,
            recallBundleSha256: is_string($recallBundleSha256) ? $recallBundleSha256 : null,
            references: $manifest->references,
            disagreements: $manifest->disagreements,
        );
    }
}
