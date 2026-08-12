<?php

declare(strict_types=1);

namespace voku\AgentLoop\Run;

use JsonException;
use RuntimeException;
use Throwable;
use voku\AgentKanban\Config\BoardConfig;
use voku\AgentKanban\Domain\CardId;
use voku\AgentKanban\Exception\ValidationException;
use voku\AgentKanban\Repository\MarkdownCardRepository;
use voku\AgentLearning\RunLearningDecisionStore;
use voku\AgentLoop\ProjectLayout;
use voku\AgentLoop\RecallOutputRoot;
use voku\AgentLoop\Workflow\ExecutionContractStore;
use voku\AgentLoop\Workflow\TaskContract;
use voku\AgentLoop\Workflow\TaskContractStore;
use voku\AgentLoop\Workflow\WorkflowLearningRoot;
use voku\AgentLoop\Workflow\WorkflowReviewReportReader;
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
        /** @var list<array{code: string, owner: string, message: string}> $disagreements */
        $disagreements = [];
        $contract = (new TaskContractStore($this->rootPath))->find($taskId);
        $run = (new GovernedRunStore($this->rootPath))->find($taskId);
        $session = $this->sessionForTask($taskId, $run, $disagreements);

        if ($run !== null && $contract !== null) {
            if ($run->contractRevision !== $contract->revision) {
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

        $references = [
            'board' => $this->boardReference($taskId, $disagreements),
            'session' => $this->sessionReference($session, $run),
            'contract' => $this->contractReference($contract),
            'approval' => $this->approvalReference($contract),
            'map' => $this->fileReference('agent-map', '.agent-loop/map/php-symbols.json', 'missing'),
            'search_index' => $this->fileReference('agent-map', '.agent-loop/map/search.sqlite', 'not_built'),
            'recall' => $this->recallReference($taskId, $disagreements),
            'execution_contract' => $this->executionContractReference($taskId),
            'edit' => $this->editReference($taskId),
            'verification' => $this->verificationReference($taskId, $run, $disagreements),
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
        $state = $this->overallState($session, $contract, $run, $references, $disagreements);
        $nextAction = $this->nextAction($taskId, $session, $contract, $run, $references, $disagreements);

        return new RunManifest($taskId, $runId, $mode, $state, $references, $disagreements, $nextAction);
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function sessionForTask(string $taskId, ?GovernedRun $run, array &$disagreements): ?Session
    {
        $root = (new ProjectLayout($this->rootPath))->sessionsRoot();
        if (!is_dir($root)) {
            return null;
        }
        $store = new SessionStore();
        if ($run !== null) {
            try {
                $session = $store->load($root, $run->sessionId);
                if ($session->taskId !== $taskId) {
                    $disagreements[] = [
                        'code' => 'session.task_mismatch',
                        'owner' => 'agent-session',
                        'message' => 'Run-bound Session belongs to another task.',
                    ];

                    return null;
                }

                return $session;
            } catch (Throwable) {
                return null;
            }
        }

        $matches = array_values(array_filter(
            $store->all($root),
            static fn (Session $session): bool => $session->taskId === $taskId,
        ));
        $active = array_values(array_filter(
            $matches,
            static fn (Session $session): bool => !$session->status->isClosed(),
        ));
        if (count($active) > 1) {
            $disagreements[] = [
                'code' => 'session.multiple_active',
                'owner' => 'agent-session',
                'message' => sprintf('Task %s has %d active Sessions.', $taskId, count($active)),
            ];

            return null;
        }

        return $active[0] ?? null;
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     * @return array<string, mixed>
     */
    private function boardReference(string $taskId, array &$disagreements): array
    {
        $boardRoot = (new ProjectLayout($this->rootPath))->boardRoot();
        $configPath = rtrim($boardRoot, '/') . '/todo/kanban.config.json';
        if (!is_file($configPath)) {
            return ['owner' => 'agent-kanban', 'state' => 'not_configured', 'observation_mode' => 'checked'];
        }
        try {
            $cardId = CardId::fromString($taskId);
        } catch (ValidationException) {
            return [
                'owner' => 'agent-kanban',
                'state' => 'ad_hoc_task',
                'observation_mode' => 'checked',
                'config' => $this->artifact($configPath),
            ];
        }
        try {
            $repository = new MarkdownCardRepository($boardRoot, BoardConfig::fromJsonFile($configPath));
            if (!$repository->exists($cardId)) {
                return [
                    'owner' => 'agent-kanban',
                    'state' => 'not_linked',
                    'observation_mode' => 'checked',
                    'card_id' => $taskId,
                    'config' => $this->artifact($configPath),
                ];
            }
            $card = $repository->load($cardId);

            return [
                'owner' => 'agent-kanban',
                'state' => 'linked',
                'observation_mode' => 'checked',
                'card_id' => $taskId,
                'revision' => $card->revision->toString(),
                'lane' => $card->lane->toString(),
                'status' => $card->status->toString(),
                'source' => $this->artifact($card->sourceFile),
                'config' => $this->artifact($configPath),
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
    private function contractReference(?TaskContract $contract): array
    {
        if ($contract === null) {
            return ['owner' => 'agent-loop', 'state' => 'missing', 'observation_mode' => 'checked'];
        }

        return [
            'owner' => 'agent-loop',
            'state' => $contract->status,
            'observation_mode' => 'checked',
            'revision' => $contract->revision,
            'goal' => $contract->goal,
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
            return [
                'owner' => 'agent-loop',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'contract_revision' => $contract->revision,
            ];
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
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     * @return array<string, mixed>
     */
    private function recallReference(string $taskId, array &$disagreements): array
    {
        $directory = RecallOutputRoot::resolve($this->rootPath) . '/' . $taskId;
        $metaPath = $directory . '/meta.json';
        if (!is_file($metaPath)) {
            return [
                'owner' => 'agent-recall-compiler',
                'state' => 'missing',
                'observation_mode' => 'checked',
                'path' => RecallOutputRoot::relativeTo($this->rootPath, $metaPath),
            ];
        }
        try {
            $meta = $this->decodeJson($metaPath);
        } catch (RuntimeException $exception) {
            $disagreements[] = ['code' => 'recall.meta_invalid', 'owner' => 'agent-recall-compiler', 'message' => $exception->getMessage()];

            return ['owner' => 'agent-recall-compiler', 'state' => 'invalid', 'observation_mode' => 'checked'];
        }
        if (is_string($meta['task_id'] ?? null) && $meta['task_id'] !== $taskId) {
            $disagreements[] = [
                'code' => 'recall.task_mismatch',
                'owner' => 'agent-recall-compiler',
                'message' => 'Recall metadata belongs to another task.',
            ];
        }

        return [
            'owner' => 'agent-recall-compiler',
            'state' => ($meta['blocked'] ?? false) === true ? 'blocked' : 'compiled',
            'observation_mode' => 'checked',
            'compilation_id' => is_string($meta['compilation_id'] ?? null) ? $meta['compilation_id'] : null,
            'bundle_sha256' => is_string($meta['bundle_sha256'] ?? null) ? $meta['bundle_sha256'] : null,
            'snapshot_sha256' => is_string($meta['snapshot_sha256'] ?? null) ? $meta['snapshot_sha256'] : null,
            'source' => $this->artifact($metaPath),
        ];
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
        $directory = rtrim($this->rootPath, '/') . '/.agent-loop/edit/' . $taskId;
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
            'path' => RelativePath::fromRoot($this->rootPath, $directory),
            'artifacts' => $artifacts,
        ];
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     * @return array<string, mixed>
     */
    private function verificationReference(string $taskId, ?GovernedRun $run, array &$disagreements): array
    {
        $receipt = (new RunVerificationReceiptStore($this->rootPath))->find($taskId);
        if ($receipt !== null) {
            if ($run !== null && $receipt->runId !== $run->runId) {
                $disagreements[] = [
                    'code' => 'verification.run_mismatch',
                    'owner' => 'agent-loop',
                    'message' => 'Verification receipt belongs to another Run.',
                ];
            }

            return [
                'owner' => 'agent-loop',
                'state' => $receipt->verdict === 'satisfied' ? 'passed' : 'failed',
                'observation_mode' => 'checked',
                'run_id' => $receipt->runId,
                'contract_revision' => $receipt->contractRevision,
                'source_session_id' => $receipt->sourceSessionId,
                'source' => $this->artifact($receipt->path),
            ];
        }

        return [
            'owner' => 'agent-loop',
            'state' => 'pending_close',
            'observation_mode' => 'checked',
            'path' => RelativePath::fromRoot($this->rootPath, (new RunVerificationReceiptStore($this->rootPath))->path($taskId)),
        ];
    }

    /**
     * @param list<array{code: string, owner: string, message: string}> $disagreements
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
     * @param list<array{code: string, owner: string, message: string}> $disagreements
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
    private function fileReference(string $owner, string $relativePath, string $missingState): array
    {
        $path = rtrim($this->rootPath, '/') . '/' . ltrim($relativePath, '/');
        if (!is_file($path)) {
            return ['owner' => $owner, 'state' => $missingState, 'observation_mode' => 'checked', 'path' => $relativePath];
        }

        return [
            'owner' => $owner,
            'state' => 'present',
            'observation_mode' => 'checked',
            'source' => $this->artifact($path),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function overallState(
        ?Session $session,
        ?TaskContract $contract,
        ?GovernedRun $run,
        array $references,
        array $disagreements,
    ): string {
        if ($disagreements !== []) {
            return 'blocked';
        }
        if ($session !== null && $session->ephemeral) {
            return 'experiment';
        }
        if ($contract === null || $contract->status !== TaskContract::APPROVED || $run === null) {
            return 'incomplete';
        }
        if (($references['recall']['state'] ?? null) !== 'compiled') {
            return 'incomplete';
        }
        if (in_array($references['execution_contract']['state'] ?? null, ['blocked', 'rejected', 'invalid', 'stale'], true)) {
            return 'blocked';
        }
        if (($references['review']['state'] ?? null) === 'fail') {
            return 'blocked';
        }
        if (in_array($references['review']['state'] ?? null, ['missing', 'invalid'], true)) {
            return 'incomplete';
        }
        if (($references['learning']['state'] ?? null) !== 'decided') {
            return 'incomplete';
        }
        if (($references['verification']['state'] ?? null) === 'failed') {
            return 'blocked';
        }
        if (($references['verification']['state'] ?? null) === 'passed') {
            return $session === null || $session->status->isClosed() ? 'complete' : 'ready_to_close';
        }

        return 'ready_to_close';
    }

    /**
     * @param array<string, array<string, mixed>> $references
     * @param list<array{code: string, owner: string, message: string}> $disagreements
     */
    private function nextAction(
        string $taskId,
        ?Session $session,
        ?TaskContract $contract,
        ?GovernedRun $run,
        array $references,
        array $disagreements,
    ): string {
        if ($disagreements !== []) {
            return 'agent-loop workflow manifest ' . $taskId . ' --format=json';
        }
        if ($session !== null && $session->ephemeral) {
            return 'agent-loop session close ' . $session->id . ' --status dropped';
        }
        if ($contract === null) {
            return 'agent-loop workflow plan ' . $taskId . ' --by <actor> --file <path> --goal "..." --validation "..."';
        }
        if ($contract->status !== TaskContract::APPROVED || $run === null) {
            return 'agent-loop workflow approve ' . $taskId . ' --by <human-actor>';
        }
        if (($references['recall']['state'] ?? null) !== 'compiled') {
            return 'agent-loop workflow approve ' . $taskId . ' --by <human-actor>';
        }
        if (in_array($references['execution_contract']['state'] ?? null, ['missing', 'pending_recall'], true)) {
            return 'agent-loop workflow contract ' . $taskId . ' --status ready --from <l1.md> --by <actor>';
        }
        if (in_array($references['execution_contract']['state'] ?? null, ['blocked', 'rejected', 'invalid', 'stale'], true)) {
            return 'agent-loop workflow status ' . $taskId . ' --format=json';
        }
        if (in_array($references['review']['state'] ?? null, ['missing', 'invalid', 'fail'], true)) {
            return 'agent-loop review blindspots ' . $taskId;
        }
        if (($references['learning']['state'] ?? null) !== 'decided') {
            return 'agent-loop workflow learn ' . $taskId . ' --status no_durable_learning --by <actor> --reason "..."';
        }
        if (($references['verification']['state'] ?? null) !== 'passed') {
            return 'agent-loop workflow close ' . $taskId . ' --status done';
        }
        if ($session === null || $session->status->isClosed()) {
            return 'none';
        }

        return 'agent-loop workflow close ' . $taskId . ' --status done';
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

        return ['path' => RelativePath::fromRoot($this->rootPath, $path), 'sha256' => 'sha256:' . $sha];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $path): array
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Unable to read JSON artifact: ' . $path);
        }
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid JSON artifact ' . $path . ': ' . $exception->getMessage(), 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Invalid JSON artifact: ' . $path);
        }

        return $decoded;
    }
}
