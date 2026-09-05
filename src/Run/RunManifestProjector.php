<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use RuntimeException;
use Throwable;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Repository\BoardConfigurationMode;
use voku\AgentKanban\Repository\BoardContext;
use voku\AgentKanban\Repository\BoardContextResolution;
use voku\AgentKanban\Repository\BoardContextResolver;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\PathResolver;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\ExecutionContractStore;
use voku\AgentLoop\Workflow\ImplementationSnapshot;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\ValidationDiagnosticStore;
use voku\AgentLoop\Workflow\WorkflowCloseReadiness;
use voku\AgentLoop\Workflow\WorkflowCloseReadinessInspector;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
use voku\AgentMap\Inspect\MapReadiness;
use voku\AgentMap\Inspect\MapReadinessInspector;
use voku\AgentMap\MapArtifactPaths;
use voku\AgentRecallCompiler\Output\CompiledRecallOutput;
use voku\AgentRecallCompiler\Output\CompiledRecallOutputReader;
use voku\AgentSession\AmbiguousActiveSession;
use voku\AgentSession\Session;
use voku\AgentSession\SessionStore;

/**
 * Read-only projection of one governed task across package-owned artifacts.
 * Session state may disappear after close; durable run state must not.
 */
final readonly class RunManifestProjector
{
    public function __construct(private string $rootPath)
    {
    }

    public function project(string $taskId): RunManifest
    {
        /** @var list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements */
        $disagreements = [];
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        $session = $this->sessionForTask($taskId, $run, $disagreements);

        if ($run !== null && $contract !== null) {
            if ($run->contractRevision !== $contract->revision) {
                if (!$this->approvedContractSupersedesRun($run, $contract)) {
                    $disagreements[] = [
                        'code' => 'run.contract_revision_mismatch',
                        'owner' => 'agent-loop',
                        'message' => sprintf(
                            'Run %s references Contract revision %d while current revision is %d.',
                            $run->runId,
                            $run->contractRevision,
                            $contract->revision,
                        ),
                    ];
                }
            } else {
                $hash = hash_file('sha256', $contract->path);
                if ($hash === false || !hash_equals($run->contractSource['sha256'], 'sha256:' . $hash)) {
                    $disagreements[] = [
                        'code' => 'run.contract_digest_mismatch',
                        'owner' => 'agent-loop',
                        'message' => 'Governed Run Contract digest does not match the current Contract artifact.',
                    ];
                }
            }
        }

        $layout = new ProjectLayout($this->rootPath);
        $mapReadiness = (new MapReadinessInspector())->inspect(
            MapArtifactPaths::forProject($this->rootPath, $layout->mapRoot()),
        );
        $references = [
            'board' => $this->boardReference($taskId, $contract, $run, $disagreements),
            'session' => $this->sessionReference($session, $run),
            'contract' => $this->contractReference($contract, $run),
            'approval' => $this->approvalReference($contract),
            'map' => $this->mapReference($mapReadiness),
            'search_index' => $this->searchIndexReference($mapReadiness),
            'recall' => $this->recallReference($taskId, $run, $contract, $disagreements),
            'execution_contract' => $this->executionContractReference($taskId),
            'edit' => $this->editReference($taskId),
            'verification' => $this->verificationReference($taskId, $run, $contract, $session, $disagreements),
            'review' => $this->reviewReference($taskId, $disagreements),
            'learning' => $this->learningReference($run, $disagreements),
            'outcome_lineage' => [
                'owner' => 'agent-learning',
                'state' => $run === null ? 'run_missing' : 'run_bound',
                'observation_mode' => 'checked',
                'run_id' => $run?->runId,
            ],
        ];

        usort(
            $disagreements,
            static fn (array $left, array $right): int => strcmp($left['code'], $right['code']),
        );

        $ephemeral = $session !== null && $session->ephemeral;
        $mode = $ephemeral ? 'ephemeral' : ($run !== null ? 'governed' : ($contract !== null ? 'planned' : 'legacy_inferred'));
        $runId = $ephemeral
            ? 'session:' . $session->id
            : ($run !== null ? $run->runId : ($contract !== null ? 'task:' . $taskId . ':planned' : 'task:' . $taskId . ':legacy'));
        $policy = (new RunPolicyEvaluator())->evaluate($taskId, $mode, $references, $disagreements);

        return new RunManifest(
            $taskId,
            $runId,
            $mode,
            $policy->state,
            $references,
            $disagreements,
            $policy->nextAction,
            $policy->nextActionKind,
        );
    }

    /**
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     */
    private function sessionForTask(string $taskId, ?GovernedRun $run, array &$disagreements): ?Session
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return null;
        }
        $store = new SessionStore();
        if ($run !== null) {
            if (!$store->exists($root, $run->sessionId)) {
                return null;
            }

            $session = null;
            try {
                $session = $store->load($root, $run->sessionId);
            } catch (Throwable $exception) {
                $disagreements[] = [
                    'code' => 'session.unreadable',
                    'owner' => 'agent-session',
                    'message' => $exception->getMessage(),
                ];
            }
            if ($session === null) {
                return null;
            }
            if ($session->taskId !== $taskId) {
                $disagreements[] = [
                    'code' => 'session.task_mismatch',
                    'owner' => 'agent-session',
                    'message' => 'Run-bound Session belongs to another task.',
                ];

                return null;
            }

            return $session;
        }

        $ambiguous = false;
        try {
            $active = $store->activeForTask($root, $taskId);
        } catch (AmbiguousActiveSession $exception) {
            $disagreements[] = [
                'code' => 'session.multiple_active',
                'owner' => 'agent-session',
                'message' => $exception->getMessage(),
            ];
            $ambiguous = true;
        }
        if ($ambiguous) {
            return null;
        }
        if ($active !== null) {
            return $active;
        }

        $ephemeral = array_values(array_filter(
            $store->openForTask($root, $taskId),
            static fn (Session $session): bool => $session->ephemeral,
        ));

        return count($ephemeral) === 1 ? $ephemeral[0] : null;
    }

    /**
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     * @return array<string, mixed>
     */
    private function boardReference(
        string $taskId,
        ?TaskContract $contract,
        ?GovernedRun $run,
        array &$disagreements,
    ): array {
        $boardRoot = (new ProjectLayout($this->rootPath))->boardRoot();
        try {
            $resolver = new BoardContextResolver();
            $resolution = $resolver->resolveOptionalWithProvenance($boardRoot);
            if ($resolution === null) {
                return ['owner' => 'agent-kanban', 'state' => 'not_configured', 'observation_mode' => 'checked'];
            }

            $context = $resolution->context;
            $configuration = $this->boardConfigurationReference($resolution);
            try {
                $cardId = CardId::fromString($taskId);
            } catch (ValidationException) {
                return [
                    'owner' => 'agent-kanban',
                    'state' => 'ad_hoc_task',
                    'observation_mode' => 'checked',
                    'configuration' => $configuration,
                ];
            }
            if ($context->config->projectPrefix !== $cardId->prefix) {
                $context = $this->contextForPrefix($resolver->resolveAll($boardRoot), $cardId->prefix);
            }
            if ($context === null || !$context->repository->exists($cardId)) {
                return [
                    'owner' => 'agent-kanban',
                    'state' => 'not_linked',
                    'observation_mode' => 'checked',
                    'card_id' => $taskId,
                    'configuration' => $configuration,
                ];
            }
            $card = $context->repository->load($cardId);
            if (
                $contract === null
                && $run === null
                && $this->usesDefaultBoardTopology($context->config)
                && $card->lane->toString() === 'DOING'
            ) {
                $disagreements[] = [
                    'code' => 'board.active_without_governed_lifecycle',
                    'owner' => 'agent-loop',
                    'message' => sprintf(
                        'Default Kanban card %s is in DOING while agent-loop has no Contract or governed Run. '
                        . 'Create and approve a Contract and enter a governed Run before keeping the card in DOING, '
                        . 'or move the card to a non-active lane.',
                        $taskId,
                    ),
                ];
            }

            return [
                'owner' => 'agent-kanban',
                'state' => 'linked',
                'observation_mode' => 'checked',
                'card_id' => $taskId,
                'revision' => $card->revision->toString(),
                'lane' => $card->lane->toString(),
                'status' => $card->status->toString(),
                'source' => $this->artifact(rtrim($context->rootPath, '/') . '/' . $card->sourceFile),
                'configuration' => $configuration,
            ];
        } catch (Throwable $exception) {
            $disagreements[] = [
                'code' => 'board.unreadable',
                'owner' => 'agent-kanban',
                'message' => $exception->getMessage(),
            ];

            return ['owner' => 'agent-kanban', 'state' => 'invalid', 'observation_mode' => 'checked'];
        }
    }

    /**
     * @param array<string, BoardContext> $contexts
     */
    private function contextForPrefix(array $contexts, string $prefix): ?BoardContext
    {
        foreach ($contexts as $context) {
            if ($context->config->projectPrefix === $prefix) {
                return $context;
            }
        }

        return null;
    }

    /** @return array{mode: 'json'|'metadata'|'inferred', source?: array{path: string, sha256: string}} */
    private function boardConfigurationReference(BoardContextResolution $resolution): array
    {
        $mode = match ($resolution->configurationMode) {
            BoardConfigurationMode::JSON => 'json',
            BoardConfigurationMode::METADATA => 'metadata',
            BoardConfigurationMode::INFERRED => 'inferred',
        };
        if ($resolution->configurationSourcePath === null) {
            return ['mode' => $mode];
        }

        return [
            'mode' => $mode,
            'source' => $this->artifact($resolution->configurationSourcePath),
        ];
    }

    private function usesDefaultBoardTopology(BoardConfig $config): bool
    {
        return $config->lanes === BoardConfig::DEFAULT_LANES
            && $config->transitions === BoardConfig::DEFAULT_TRANSITIONS;
    }

    /** @return array<string, mixed> */
    private function sessionReference(?Session $session, ?GovernedRun $run): array
    {
        if ($session === null) {
            return [
                'owner' => 'agent-session',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'expected_session_id' => $run?->sessionId,
            ];
        }

        return [
            'owner' => 'agent-session',
            'state' => $session->status->value,
            'observation_mode' => 'checked',
            'session_id' => $session->id,
            'task_id' => $session->taskId,
            'ephemeral' => $session->ephemeral,
            'base_commit' => $session->baseCommit,
            'source' => $this->artifact($session->path . '/session.json'),
        ];
    }

    /** @return array<string, mixed> */
    private function contractReference(?TaskContract $contract, ?GovernedRun $run): array
    {
        if ($contract === null) {
            return ['owner' => 'agent-loop', 'state' => 'missing', 'observation_mode' => 'checked'];
        }

        return [
            'owner' => 'agent-loop',
            'state' => $contract->status,
            'observation_mode' => 'checked',
            'revision' => $contract->revision,
            'run_revision' => $run?->contractRevision,
            'goal' => $contract->goal,
            'acceptance_criteria' => $contract->acceptanceCriteria,
            'source' => $this->artifact($contract->path),
        ];
    }

    /** @return array<string, mixed> */
    private function approvalReference(?TaskContract $contract): array
    {
        if ($contract === null) {
            return ['owner' => 'agent-loop', 'state' => 'unavailable', 'observation_mode' => 'checked'];
        }
        if ($contract->status !== TaskContract::APPROVED || $contract->approvedBy === null || $contract->approvedAt === null) {
            $reference = [
                'owner' => 'agent-loop',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'contract_revision' => $contract->revision,
            ];
            return $reference;
        }

        return [
            'owner' => 'agent-loop',
            'state' => 'current',
            'observation_mode' => 'checked',
            'contract_revision' => $contract->revision,
            'approved_by' => $contract->approvedBy,
            'approved_at' => $contract->approvedAt,
            'source' => $this->artifact($contract->path),
        ];
    }

    /**
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     * @return array<string, mixed>
     */
    private function recallReference(
        string $taskId,
        ?GovernedRun $run,
        ?TaskContract $contract,
        array &$disagreements,
    ): array {
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        $reader = new CompiledRecallOutputReader();
        try {
            $output = $reader->read($directory);
        } catch (RuntimeException $exception) {
            $disagreements[] = ['code' => 'recall.meta_invalid', 'owner' => 'agent-recall-compiler', 'message' => $exception->getMessage()];

            return ['owner' => 'agent-recall-compiler', 'state' => 'invalid', 'observation_mode' => 'checked'];
        }
        if ($output === null) {
            return [
                'owner' => 'agent-recall-compiler',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'path' => RecallOutputRoot::relativeTo($this->rootPath, $reader->identityPath($directory)),
            ];
        }
        if (!$output->describesTask($taskId)) {
            $disagreements[] = [
                'code' => 'recall.task_mismatch',
                'owner' => 'agent-recall-compiler',
                'message' => 'Recall metadata belongs to another task.',
            ];
        }

        $bindingFailure = $this->recallBindingFailure($output, $run, $contract);
        if ($bindingFailure !== null) {
            return [
                'owner' => 'agent-recall-compiler',
                'state' => 'stale',
                'observation_mode' => 'checked',
                'reason' => $bindingFailure,
                'compilation_id' => $output->compilationId(),
                'source' => $this->artifact($output->identityPath()),
            ];
        }

        return [
            'owner' => 'agent-recall-compiler',
            'state' => $output->isBlocked() ? 'blocked' : 'compiled',
            'observation_mode' => 'checked',
            'compilation_id' => $output->compilationId(),
            'bundle_sha256' => $output->bundleSha256(),
            'snapshot_sha256' => $output->snapshotSha256(),
            'source' => $this->artifact($output->identityPath()),
        ];
    }

    private function recallBindingFailure(
        CompiledRecallOutput $output,
        ?GovernedRun $run,
        ?TaskContract $contract,
    ): ?string {
        if ($run === null || $contract === null) {
            return null;
        }

        if (!$output->hasBundle()) {
            return $output->isComplete()
                ? null
                : 'Recall output is incomplete for the current governed Run: the compiled bundle is missing.';
        }
        if (!$output->isBundleReadable()) {
            return 'Recall bundle is unreadable for the current governed Run.';
        }
        if (
            !$output->bindsTo($run->taskId, $run->contractRevision)
            || !$output->bindsTo($contract->taskId, $contract->revision)
        ) {
            return sprintf(
                'Recall bundle does not match the current governed Run/Contract binding (Run revision %d, Contract revision %d).',
                $run->contractRevision,
                $contract->revision,
            );
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function executionContractReference(string $taskId): array
    {
        try {
            return (new ExecutionContractStore($this->rootPath))->inspect($taskId);
        } catch (Throwable $exception) {
            return [
                'owner' => 'agent-loop',
                'state' => 'invalid',
                'observation_mode' => 'checked',
                'reason' => $exception->getMessage(),
            ];
        }
    }

    /** @return array<string, mixed> */
    private function editReference(string $taskId): array
    {
        $directory = (new ProjectLayout($this->rootPath))->editBundle($taskId);
        if (!is_dir($directory)) {
            return ['owner' => 'agent-loop', 'state' => 'none', 'observation_mode' => 'checked'];
        }
        $artifacts = [];
        foreach (['request.json', 'execution.json', 'agent-result.json'] as $file) {
            $path = $directory . '/' . $file;
            if (is_file($path)) {
                $artifacts[$file] = $this->artifact($path);
            }
        }

        return [
            'owner' => 'agent-loop',
            'state' => 'present',
            'observation_mode' => 'checked',
            'path' => PathResolver::relativeTo($this->rootPath, $directory),
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     * @return array<string, mixed>
     */
    private function verificationReference(
        string $taskId,
        ?GovernedRun $run,
        ?TaskContract $contract,
        ?Session $session,
        array &$disagreements,
    ): array {
        $store = new RunVerificationReceiptStore($this->rootPath);
        if ($this->approvedContractSupersedesRun($run, $contract)) {
            return [
                'owner' => 'agent-loop',
                'state' => 'pending_close',
                'observation_mode' => 'checked',
                'path' => PathResolver::relativeTo($this->rootPath, $store->path($taskId)),
            ];
        }

        $receipt = $store->find($taskId);
        if ($receipt !== null) {
            if ($run !== null && $receipt->runId !== $run->runId) {
                $disagreements[] = [
                    'code' => 'verification.run_mismatch',
                    'owner' => 'agent-loop',
                    'message' => 'Verification receipt belongs to another Run.',
                ];
            }

            if (
                $contract !== null
                && $run !== null
                && $run->contractRevision === $contract->revision
            ) {
                if ($receipt->implementationSnapshot === null) {
                    return [
                        'owner' => 'agent-loop',
                        'state' => 'stale',
                        'observation_mode' => 'checked',
                        'reason' => 'Verification receipt predates implementation-snapshot binding and cannot prove currentness.',
                        'run_id' => $receipt->runId,
                        'contract_revision' => $receipt->contractRevision,
                        'implementation_snapshot' => null,
                        'source_session_id' => $receipt->sourceSessionId,
                        'source' => $this->artifact($receipt->path),
                    ];
                }

                try {
                    $currentImplementation = ImplementationSnapshot::capture($this->rootPath, $contract);
                } catch (Throwable $exception) {
                    $disagreements[] = [
                        'code' => 'verification.current_snapshot_unavailable',
                        'owner' => 'agent-loop',
                        'message' => $exception->getMessage(),
                    ];

                    return [
                        'owner' => 'agent-loop',
                        'state' => 'invalid',
                        'observation_mode' => 'checked',
                        'reason' => 'Current implementation snapshot could not be established.',
                        'source' => $this->artifact($receipt->path),
                    ];
                }

                if (!hash_equals($receipt->implementationSnapshot, $currentImplementation->digest)) {
                    return [
                        'owner' => 'agent-loop',
                        'state' => 'stale',
                        'observation_mode' => 'checked',
                        'reason' => 'Verification receipt describes a different implementation snapshot.',
                        'run_id' => $receipt->runId,
                        'contract_revision' => $receipt->contractRevision,
                        'implementation_snapshot' => $receipt->implementationSnapshot,
                        'current_implementation_snapshot' => $currentImplementation->digest,
                        'source_session_id' => $receipt->sourceSessionId,
                        'source' => $this->artifact($receipt->path),
                    ];
                }
            }

            $state = match ($receipt->verdict) {
                'satisfied' => 'passed',
                'accepted_risk' => 'accepted_risk',
                default => 'failed',
            };

            return [
                'owner' => 'agent-loop',
                'state' => $state,
                'observation_mode' => 'checked',
                'run_id' => $receipt->runId,
                'contract_revision' => $receipt->contractRevision,
                'implementation_snapshot' => $receipt->implementationSnapshot,
                'source_session_id' => $receipt->sourceSessionId,
                'source' => $this->artifact($receipt->path),
            ];
        }

        if (
            $contract !== null
            && $contract->status === TaskContract::APPROVED
            && $run !== null
            && $session !== null
            && !$session->ephemeral
        ) {
            try {
                $readiness = (new WorkflowCloseReadinessInspector($this->rootPath))->inspect($contract, $run, $session);
            } catch (Throwable $exception) {
                $disagreements[] = [
                    'code' => 'verification.readiness_unreadable',
                    'owner' => 'agent-loop',
                    'message' => $exception->getMessage(),
                ];

                return ['owner' => 'agent-loop', 'state' => 'invalid', 'observation_mode' => 'checked'];
            }

            if ($readiness->isReady()) {
                return [
                    'owner' => 'agent-loop',
                    'state' => 'ready',
                    'observation_mode' => 'checked',
                    'implementation_snapshot' => $readiness->boundary?->implementation->digest,
                    'path' => PathResolver::relativeTo($this->rootPath, $store->path($taskId)),
                ];
            }

            $failure = $readiness->firstFailure();
            $repairAction = null;
            $diagnosticStore = new ValidationDiagnosticStore($this->rootPath);
            if ($diagnosticStore->find($taskId) !== null && $diagnosticStore->canAttemptRepair($taskId)) {
                $repairAction = 'agent-loop repair ' . $taskId;
            }

            return [
                'owner' => 'agent-loop',
                'state' => 'blocked',
                'observation_mode' => 'checked',
                'gate' => $failure['gate'] ?? 'unknown',
                'reason' => $failure['detail'] ?? 'workflow close readiness failed without detail',
                'action' => $this->closeReadinessAction($taskId, $readiness),
                'repair_action' => $repairAction,
                'validation_failed' => $readiness->hasFailedValidationEvidence(),
                'implementation_snapshot' => $readiness->boundary?->implementation->digest,
            ];
        }

        return [
            'owner' => 'agent-loop',
            'state' => 'pending_close',
            'observation_mode' => 'checked',
            'path' => PathResolver::relativeTo($this->rootPath, $store->path($taskId)),
        ];
    }

    private function closeReadinessAction(string $taskId, WorkflowCloseReadiness $readiness): string
    {
        $failure = $readiness->firstFailure();
        if (($failure['gate'] ?? null) === 'validation') {
            return $readiness->firstUnsatisfiedValidationCommand()
                ?? 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if (($failure['gate'] ?? null) === 'verify') {
            return 'agent-loop verify --task-id=' . $taskId;
        }
        if (($failure['gate'] ?? null) === 'recall_outcomes') {
            $draft = PathResolver::relativeTo(
                $this->rootPath,
                RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId . '/recall-log.draft.json',
            );

            return 'complete the Recall outcome judgment in ' . $draft
                . ', then run agent-loop finish ' . $taskId
                . ' --recall-outcome-draft ' . $draft
                . ' --by <actor> --commit <commit>';
        }

        return 'agent-loop workflow status ' . $taskId . ' --format=json';
    }

    /**
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     * @return array<string, mixed>
     */
    private function reviewReference(string $taskId, array &$disagreements): array
    {
        $reader = new WorkflowReviewReportReader($this->rootPath);
        $report = $reader->read($taskId);
        if (!$report['exists']) {
            return [
                'owner' => 'agent-recall-compiler',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'path' => $reader->relativePath($taskId),
            ];
        }
        if ($report['invalid']) {
            $disagreements[] = [
                'code' => 'review.report_invalid',
                'owner' => 'agent-recall-compiler',
                'message' => $reader->relativePath($taskId) . ' is not a valid blind-spot report.',
                'repair_action' => 'agent-loop review blindspots ' . $taskId,
            ];

            return ['owner' => 'agent-recall-compiler', 'state' => 'invalid', 'observation_mode' => 'checked'];
        }

        return [
            'owner' => 'agent-recall-compiler',
            'state' => $report['status'],
            'observation_mode' => 'checked',
            'source' => $this->artifact($reader->absolutePath($taskId)),
        ];
    }

    /**
     * @param list<array{code: string, owner: string, message: string, repair_action?: string}> $disagreements
     * @return array<string, mixed>
     */
    private function learningReference(?GovernedRun $run, array &$disagreements): array
    {
        if ($run === null) {
            return ['owner' => 'agent-learning', 'state' => 'unavailable', 'observation_mode' => 'checked'];
        }
        try {
            $root = WorkflowLearningRoot::forRun($this->rootPath, $run);
            $decision = (new RunLearningDecisionStore($root))->find($run->runId);
        } catch (Throwable $exception) {
            $disagreements[] = [
                'code' => 'learning.unreadable',
                'owner' => 'agent-learning',
                'message' => $exception->getMessage(),
            ];

            return ['owner' => 'agent-learning', 'state' => 'invalid', 'observation_mode' => 'checked'];
        }
        if ($decision === null) {
            return ['owner' => 'agent-learning', 'state' => 'missing', 'observation_mode' => 'checked', 'run_id' => $run->runId];
        }

        return [
            'owner' => 'agent-learning',
            'state' => 'decided',
            'decision' => $decision->decision->value,
            'observation_mode' => 'checked',
            'run_id' => $decision->runId,
            'decided_by' => $decision->decidedBy,
            'decided_at' => $decision->decidedAt,
            'source' => $this->artifact($decision->path),
        ];
    }

    /** @return array<string, mixed> */
    private function mapReference(MapReadiness $readiness): array
    {
        $reference = $this->agentMapArtifactReference(
            $readiness->mapState,
            $readiness->mapPath,
            $readiness->mapSnapshot,
            $readiness->mapFailure,
        );
        if ($readiness->staleEntries !== []) {
            $reference['stale_entries'] = $readiness->staleEntries;
        }

        return $reference;
    }

    /** @return array<string, mixed> */
    private function searchIndexReference(MapReadiness $readiness): array
    {
        return $this->agentMapArtifactReference(
            $readiness->searchState,
            $readiness->searchPath,
            $readiness->searchSnapshot,
            $readiness->searchFailure,
        );
    }

    /** @return array<string, mixed> */
    private function agentMapArtifactReference(string $state, string $path, ?string $snapshot, ?string $failure): array
    {
        $reference = [
            'owner' => 'agent-map',
            'state' => $state,
            'observation_mode' => 'checked',
            'path' => PathResolver::relativeTo($this->rootPath, $path),
        ];
        if ($snapshot !== null) {
            $reference['snapshot'] = $snapshot;
        }
        if ($failure !== null) {
            $reference['reason'] = $failure;
        }
        if (is_file($path)) {
            $reference['source'] = $this->artifact($path);
        }

        return $reference;
    }

    private function approvedContractSupersedesRun(?GovernedRun $run, ?TaskContract $contract): bool
    {
        return $run !== null
            && $contract !== null
            && $contract->status === TaskContract::APPROVED
            && $run->contractRevision < $contract->revision;
    }

    /** @return array{path: string, sha256: string} */
    private function artifact(string $path): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Referenced artifact does not exist: ' . $path);
        }
        $sha = hash_file('sha256', $path);
        if ($sha === false) {
            throw new RuntimeException('Unable to hash referenced artifact: ' . $path);
        }

        return ['path' => PathResolver::relativeTo($this->rootPath, $path), 'sha256' => 'sha256:' . $sha];
    }

}
