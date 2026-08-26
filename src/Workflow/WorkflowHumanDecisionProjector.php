<?php

declare(strict_types=1);

namespace voku\AgentLoop\Workflow;

use voku\AgentLoop\PathResolver;
use voku\AgentLoop\Run\GovernedRunStore;
use voku\AgentLoop\Run\RunPolicyEvaluation;

/**
 * Non-authoritative host projection of the exact subject behind a human gate.
 *
 * Authority remains in TaskContractStore, WorkflowHumanDecisionService, the
 * exact review identity, Learning, and accepted-risk owners. This class only
 * makes those already-owned facts difficult for a host to hide from a human.
 */
final readonly class WorkflowHumanDecisionProjector
{
    public function __construct(private string $rootPath)
    {
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $blockers
     * @return array<string, mixed>|null
     */
    public function project(
        string $taskId,
        string $nextAction,
        string $nextActionKind,
        array $blockers = [],
    ): ?array {
        if ($nextActionKind !== RunPolicyEvaluation::KIND_DECISION_REQUIRED) {
            return null;
        }

        $available = (new WorkflowHumanDecisionService($this->rootPath))->availableActions($taskId);
        if ($available->allows(WorkflowHumanDecisionProjection::APPROVE_CONTRACT)) {
            return $this->contractApproval($taskId, $nextAction);
        }
        if ($available->allows(WorkflowHumanDecisionProjection::ACKNOWLEDGE_REVIEW)) {
            return $this->reviewAcknowledgement($taskId, $nextAction);
        }
        if ($available->allows(WorkflowHumanDecisionProjection::RECORD_LEARNING)) {
            return $this->learningDisposition($taskId, $nextAction);
        }
        if (str_contains($nextAction, '--accept-risk')) {
            return $this->riskAcceptance($taskId, $nextAction, $blockers);
        }
        if (str_contains($nextAction, 'workflow plan --supersede')) {
            return $this->contractSupersession($taskId, $nextAction, $blockers);
        }

        return $this->genericDecision($taskId, $nextAction, $blockers);
    }

    /** @return array<string, mixed> */
    private function contractApproval(string $taskId, string $nextAction): array
    {
        $contract = (new TaskContractStore($this->rootPath))->load($taskId);

        return $this->decision(
            'contract_approval',
            $taskId,
            $nextAction,
            [
                'contract_revision' => $contract->revision,
                'goal' => $contract->goal,
                'scope' => $contract->scope,
                'non_goals' => $contract->nonGoals,
                'acceptance_criteria' => $contract->acceptanceCriteria,
                'validation' => $contract->validation,
                'behavior_anchors' => $contract->behaviorAnchors,
                'operating_prompts' => $contract->operatingPrompts,
                'contract_path' => PathResolver::relativeTo($this->rootPath, $contract->path),
            ],
        );
    }

    /** @return array<string, mixed> */
    private function reviewAcknowledgement(string $taskId, string $nextAction): array
    {
        $detail = (new WorkflowReviewReportReader($this->rootPath))->detail($taskId);
        $html = new WorkflowHumanReviewCommand($this->rootPath);
        $severity = [];
        foreach ($detail->findings as $finding) {
            $severity[$finding->severity] = ($severity[$finding->severity] ?? 0) + 1;
        }
        ksort($severity, SORT_STRING);

        return $this->decision(
            'review_acknowledgement',
            $taskId,
            $nextAction,
            [
                'review_sha256' => $detail->sha256,
                'report_status' => $detail->reportStatus,
                'contract_revision' => $detail->contractRevision,
                'implementation_snapshot' => $detail->implementationSnapshot,
                'findings_summary' => [
                    'total' => count($detail->findings),
                    'by_severity' => $severity,
                ],
                'findings' => array_map(static fn ($finding): array => $finding->toArray(), $detail->findings),
                'review_path' => $detail->path,
                'presentation' => [
                    'kind' => 'html',
                    'path' => PathResolver::relativeTo($this->rootPath, $html->path($taskId)),
                    'exists' => is_file($html->path($taskId)),
                    'command' => 'agent-loop workflow review ' . $taskId,
                ],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function learningDisposition(string $taskId, string $nextAction): array
    {
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        $review = (new WorkflowReviewReportReader($this->rootPath))->detail($taskId);

        return $this->decision(
            'learning_disposition',
            $taskId,
            $nextAction,
            [
                'run_id' => $run?->runId,
                'contract_revision' => $review->contractRevision,
                'implementation_snapshot' => $review->implementationSnapshot,
                'review_sha256' => $review->sha256,
                'review_status' => $review->reportStatus,
                'allowed_dispositions' => [
                    'no_durable_learning',
                    'findings_recorded',
                    'follow_up_required',
                ],
                'reason_required' => true,
            ],
        );
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $blockers
     * @return array<string, mixed>
     */
    private function riskAcceptance(string $taskId, string $nextAction, array $blockers): array
    {
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);

        return $this->decision(
            'risk_acceptance',
            $taskId,
            $nextAction,
            [
                'contract_revision' => $contract?->revision,
                'goal' => $contract?->goal,
                'blockers' => $blockers,
            ],
        );
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $blockers
     * @return array<string, mixed>
     */
    private function contractSupersession(string $taskId, string $nextAction, array $blockers): array
    {
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        $execution = (new ExecutionContractStore($this->rootPath))->inspect($taskId);

        return $this->decision(
            'contract_supersession',
            $taskId,
            $nextAction,
            [
                'current_contract_revision' => $contract?->revision,
                'goal' => $contract?->goal,
                'scope' => $contract === null ? [] : $contract->scope,
                'execution_contract_state' => $execution['state'] ?? null,
                'minimum_contract_change' => $execution['minimum_contract_change'] ?? null,
                'reason' => $execution['reason'] ?? null,
                'blockers' => $blockers,
            ],
        );
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $blockers
     * @return array<string, mixed>
     */
    private function genericDecision(string $taskId, string $nextAction, array $blockers): array
    {
        return $this->decision(
            'human_authority',
            $taskId,
            $nextAction,
            ['blockers' => $blockers],
        );
    }

    /**
     * @param array<string, mixed> $subject
     * @return array<string, mixed>
     */
    private function decision(string $type, string $taskId, string $nextAction, array $subject): array
    {
        return [
            'schema_version' => '1.0',
            'type' => $type,
            'authority' => 'human_required',
            'task_id' => $taskId,
            'action' => $nextAction,
            'subject' => $subject,
        ];
    }
}
